import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  const ApiException(this.message);

  final String message;

  @override
  String toString() => message;
}

class SubcontractorApi {
  SubcontractorApi({http.Client? client, FlutterSecureStorage? storage})
    : _client = client ?? http.Client(),
      _storage = storage ?? const FlutterSecureStorage();

  static const String baseUrl = String.fromEnvironment(
    'SUBCONTRACTOR_API_BASE_URL',
    defaultValue: 'http://172.16.200.21/QHSE_System',
  );

  static const String _tokenKey = 'subcontractor_api_token';

  final http.Client _client;
  final FlutterSecureStorage _storage;

  // LOGIN
  Future<Map<String, dynamic>> login(String username, String password) async {
    final response = await _request(
      'login.php',
      method: 'POST',
      body: {'username': username, 'password': password},
      authenticated: false,
    );

    final token = response['token'];

    if (token is! String || token.isEmpty) {
      throw const ApiException('Login response did not include an API token.');
    }

    await _storage.write(key: _tokenKey, value: token);

    final user = _asMap(response['user']);
    return {
      ...user,
      'role': response['role']?.toString() ?? 'subcontractor',
      'web_token': response['web_token']?.toString() ?? '',
    };
  }

  // LOGIN WITH HANDOFF TICKET
  Future<Map<String, dynamic>> loginWithHandoffTicket(String ticket) async {
    final response = await _request(
      'mobile_app_login.php',
      method: 'POST',
      body: {'ticket': ticket},
      authenticated: false,
    );

    final token = response['token'];
    if (token is! String || token.isEmpty) {
      throw const ApiException('App sign-in response did not include a token.');
    }
    await _storage.write(key: _tokenKey, value: token);

    return {
      ..._asMap(response['user']),
      'role': response['role']?.toString() ?? '',
    };
  }

  // DASHBOARD
  // query keys: limit, permit_type, status, search, page
  Future<Map<String, dynamic>> dashboard({Map<String, String>? query}) {
    return _request('subcontractor_dashboard.php', query: query);
  }

  // PERMITS (old simple list)
  Future<List<Map<String, dynamic>>> permits() async {
    final response = await _request('permits.php');
    return _asList(response, 'permits');
  }

  // PERMIT HISTORY
  // Uses the SAME endpoint as the dashboard (already works), so no new PHP.
  Future<PermitHistory> fetchPermitHistory({String companyName = ''}) async {
    final res = await dashboard(query: {'limit': 'all', 'page': '1'});
    final d = res['dashboard'] is Map<String, dynamic>
        ? res['dashboard'] as Map<String, dynamic>
        : res;
    return PermitHistory.fromDashboard(d, companyName: companyName);
  }

  // INSPECTIONS
  Future<List<Map<String, dynamic>>> inspections() async {
    final response = await _request('inspections.php');
    return _asList(response, 'inspections');
  }

  // LOGOUT
  Future<void> logout() async {
    try {
      await _request('logout.php', method: 'POST');
    } finally {
      await _storage.delete(key: _tokenKey);
    }
  }

  // COMMON API REQUEST
  Future<Map<String, dynamic>> _request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    Map<String, String>? query,
    bool authenticated = true,
  }) async {
    final uri = Uri.parse(
      '$baseUrl/$path',
    ).replace(queryParameters: (query == null || query.isEmpty) ? null : query);

    final headers = <String, String>{'Accept': 'application/json'};

    if (body != null) {
      headers['Content-Type'] = 'application/json';
    }

    if (authenticated) {
      final token = await _storage.read(key: _tokenKey);

      if (token == null || token.isEmpty) {
        throw const ApiException('Please sign in to continue.');
      }

      headers['Authorization'] = 'Bearer $token';
    }

    try {
      final response = method == 'POST'
          ? await _client
                .post(uri, headers: headers, body: jsonEncode(body))
                .timeout(const Duration(seconds: 20))
          : await _client
                .get(uri, headers: headers)
                .timeout(const Duration(seconds: 20));

      dynamic decoded;
      try {
        decoded = jsonDecode(response.body);
      } on FormatException {
        final raw = response.body.trim();
        final snippet = raw.length > 160 ? '${raw.substring(0, 160)}...' : raw;
        throw ApiException(
          'Invalid JSON from $path (HTTP ${response.statusCode}): '
          '${snippet.isEmpty ? '[empty response]' : snippet}',
        );
      }

      if (decoded is! Map<String, dynamic>) {
        throw const ApiException('The server returned an invalid response.');
      }

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw ApiException(
          decoded['error']?.toString() ??
              'Request failed (${response.statusCode}).',
        );
      }

      if (decoded['ok'] == false) {
        throw ApiException(decoded['error']?.toString() ?? 'Request failed.');
      }

      return decoded;
    } on ApiException {
      rethrow;
    } on TimeoutException {
      throw const ApiException('The server took too long to respond.');
    } on http.ClientException {
      throw const ApiException(
        'Could not reach the server. Check the API URL, '
        'Wi-Fi connection, and XAMPP Apache server.',
      );
    }
  }

  List<Map<String, dynamic>> _asList(
    Map<String, dynamic> response,
    String key,
  ) {
    final value = response[key];

    if (value is! List) {
      return const [];
    }

    return value.whereType<Map<String, dynamic>>().toList();
  }

  Map<String, dynamic> _asMap(dynamic value) {
    return value is Map<String, dynamic> ? value : <String, dynamic>{};
  }

  void dispose() {
    _client.close();
  }
}

// ===================== PERMIT HISTORY MODELS =====================

String _titleCase(String raw) => raw
    .replaceAll('_', ' ')
    .split(' ')
    .map((w) => w.isEmpty ? w : w[0].toUpperCase() + w.substring(1))
    .join(' ');

class Permit {
  Permit({
    required this.id,
    required this.number,
    required this.category,
    required this.project,
    required this.supervisor,
    required this.status,
    required this.date,
  });

  final String id;
  final String number;
  final String category;
  final String project;
  final String supervisor;
  final String status;
  final String date;

  factory Permit.fromJson(Map<String, dynamic> j) {
    final cat = '${j['work_category'] ?? j['permit_category'] ?? 'N/A'}';
    return Permit(
      id: '${j['permit_id'] ?? j['id'] ?? ''}',
      number: '${j['permit_number'] ?? 'N/A'}',
      category: _titleCase(cat),
      project: '${j['cat_project'] ?? 'N/A'}',
      supervisor: '${j['supervisor_name'] ?? 'N/A'}',
      status: '${j['status'] ?? 'Pending'}',
      date: '${j['declaration_date'] ?? 'N/A'}',
    );
  }
}

class PermitHistory {
  PermitHistory({
    required this.companyName,
    required this.phone,
    required this.pendingCount,
    required this.permits,
  });

  final String companyName;
  final String phone;
  final int pendingCount;
  final List<Permit> permits;

  factory PermitHistory.fromDashboard(
    Map<String, dynamic> d, {
    String companyName = '',
  }) {
    final list = (d['permits_list'] is List)
        ? (d['permits_list'] as List)
              .whereType<Map<String, dynamic>>()
              .map(Permit.fromJson)
              .toList()
        : <Permit>[];

    final pending =
        int.tryParse('${d['pending'] ?? ''}') ??
        list.where((p) => p.status == 'Pending').length;

    return PermitHistory(
      companyName: companyName.isNotEmpty
          ? companyName
          : '${d['company_name'] ?? 'Subcontractor'}',
      phone: '${d['phone'] ?? 'N/A'}',
      pendingCount: pending,
      permits: list,
    );
  }
}
