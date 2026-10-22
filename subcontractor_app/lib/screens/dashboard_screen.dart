import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter/material.dart';
import '../services/subcontractor_api.dart';

int _toInt(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? 0;
int _atLeast1(int v) => v < 1 ? 1 : v;

// ======================= PALETTE =======================
class _Pal {
  const _Pal({
    required this.dark,
    required this.bg,
    required this.card,
    required this.border,
    required this.text,
    required this.muted,
    required this.accent,
    required this.heroA,
    required this.heroB,
  });

  final bool dark;
  final Color bg;
  final Color card;
  final Color border;
  final Color text;
  final Color muted;
  final Color accent;
  final Color heroA;
  final Color heroB;

  static const light = _Pal(
    dark: false,
    bg: Color(0xFFF2F6F4),
    card: Colors.white,
    border: Color(0xFFE1E8E4),
    text: Color(0xFF0B1F17),
    muted: Color(0xFF62756C),
    accent: Color(0xFF0E9F6E),
    heroA: Color(0xFF064E3B),
    heroB: Color(0xFFEA580C),
  );

  static const night = _Pal(
    dark: true,
    bg: Color(0xFF04150F),
    card: Color(0xFF0A2A1F),
    border: Color(0xFF14503B),
    text: Color(0xFFF1F8F4),
    muted: Color(0xFF8FA89C),
    accent: Color(0xFFFB923C),
    heroA: Color(0xFF053B2C),
    heroB: Color(0xFFC2410C),
  );
}

Color _statusColor(String s) {
  switch (s.toLowerCase()) {
    case 'approved':
      return const Color(0xFF10B981);
    case 'completed':
      return const Color(0xFF0D9488);
    case 'pending':
      return const Color(0xFFF59E0B);
    case 'on hold':
    case 'hold':
      return const Color(0xFF8B5CF6);
    case 'rejected':
      return const Color(0xFFE11D48);
    default:
      return const Color(0xFF64748B);
  }
}

// ======================= SCREEN =======================
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({
    super.key,
    required this.api,
    required this.companyName,
    this.logoUrl = 'assets/images/hayleys_logo.png',
    this.onViewPermit,
    this.onNotificationTap,
  });

  final SubcontractorApi api;
  final String companyName;
  final String? logoUrl; // network URL or 'assets/...' path
  final void Function(Map<String, dynamic> permit)? onViewPermit;
  final VoidCallback? onNotificationTap;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  late Future<Map<String, dynamic>> _future = _fetch();
  Map<String, dynamic>? _last; // keeps old data visible while reloading
  Map<String, dynamic>? _base; // last response with no filters (for stats)
  final _searchCtrl = TextEditingController();
  Timer? _debounce;

  bool _isDarkMode = false;

  String _limit = '5';
  String _type = '';
  String _status = '';
  String _search = '';
  int _page = 1;

  static const _limits = ['5', '10', '25', '50', 'all'];
  // value sent to the API (same format as permit['work_category']) -> label
  static const _types = {
    'general': 'General',
    'hot_work': 'Hot Work',
    'work_at_height': 'Work at Height',
  };
  static const _statuses = [
    'Approved',
    'Completed',
    'Pending',
    'On Hold',
    'Rejected',
    'Expired',
  ];

  @override
  void dispose() {
    _debounce?.cancel();
    _searchCtrl.dispose();
    super.dispose();
  }

  // 'Hot Work' / 'hot_work' / 'hot work'  ->  'hot_work'
  static String _slug(dynamic v) =>
      '${v ?? ''}'.trim().toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '_');

  Future<Map<String, dynamic>> _fetch() async {
    // Permit type is filtered here in the app (reliable whatever format the
    // server uses), so in that case we ask the server for every row.
    final typeFilter = _type.isNotEmpty;

    final queryParams = <String, String>{
      'limit': typeFilter ? 'all' : _limit,
      if (_status.isNotEmpty) 'status': _status,
      if (_search.isNotEmpty) 'search': _search,
      'page': typeFilter ? '1' : '$_page',
    };

    debugPrint('[Dashboard] request query: $queryParams (type=$_type)');
    final res = await widget.api.dashboard(query: queryParams);
    var out = res['dashboard'] is Map<String, dynamic>
        ? res['dashboard'] as Map<String, dynamic>
        : res;

    if (typeFilter) {
      final all = (out['permits_list'] is List)
          ? (out['permits_list'] as List)
                .whereType<Map<String, dynamic>>()
                .toList()
          : <Map<String, dynamic>>[];
      final want = _slug(_type);
      final filtered = all
          .where((p) => _slug(p['work_category']) == want)
          .toList();

      final size = _limit == 'all'
          ? math.max(1, filtered.length)
          : (int.tryParse(_limit) ?? 5);
      final totalPages = math.max(1, (filtered.length / size).ceil());
      final page = _page.clamp(1, totalPages).toInt();
      final slice = filtered.skip((page - 1) * size).take(size).toList();

      out = {
        ...out,
        'permits_list': slice,
        'page': page,
        'total_pages': totalPages,
      };
    }

    _last = out;
    if (_type.isEmpty && _status.isEmpty && _search.isEmpty) _base = out;
    final list = out['permits_list'];
    debugPrint(
      '[Dashboard] showing: permits=${list is List ? list.length : 'n/a'} '
      'page=${out['page']} total_pages=${out['total_pages']}',
    );
    return out;
  }

  void _reload({bool resetPage = true}) {
    setState(() {
      if (resetPage) _page = 1;
      _future = _fetch();
    });
  }

  bool get _filtersActive =>
      _type.isNotEmpty ||
      _status.isNotEmpty ||
      _search.isNotEmpty ||
      _limit != '5';

  void _resetFilters() {
    _searchCtrl.clear();
    setState(() {
      _limit = '5';
      _type = '';
      _status = '';
      _search = '';
      _page = 1;
      _future = _fetch();
    });
  }

  void _setStatus(String s) {
    setState(() {
      _status = _status == s ? '' : s;
      _page = 1;
      _future = _fetch();
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = _isDarkMode ? _Pal.night : _Pal.light;

    return ColoredBox(
      color: c.bg,
      child: SafeArea(
        child: RefreshIndicator(
          color: c.accent,
          backgroundColor: c.card,
          onRefresh: () async {
            _reload(resetPage: false);
            try {
              await _future;
            } catch (_) {}
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 28),
            children: [
              _hero(c),
              const SizedBox(height: 14),
              _overview(c),
              const SizedBox(height: 18),
              _permitsSection(c),
            ],
          ),
        ),
      ),
    );
  }

  // ---------- HERO ----------
  Widget _hero(_Pal c) {
    const wd = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const mo = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    final n = DateTime.now();
    final date = '${wd[n.weekday - 1]}, ${mo[n.month - 1]} ${n.day}, ${n.year}';

    String greeting = 'Good morning';
    if (n.hour >= 12 && n.hour < 17) {
      greeting = 'Good afternoon';
    } else if (n.hour >= 17) {
      greeting = 'Good evening';
    }

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: const Alignment(-0.8, -0.8),
          end: const Alignment(1.0, 1.0),
          stops: const [0.25, 1.0],
          colors: [c.heroA, c.heroB],
        ),
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: c.heroA.withOpacity(0.35),
            blurRadius: 22,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Stack(
        children: [
          Positioned(
            right: -24,
            bottom: -28,
            child: Icon(
              Icons.verified_user_outlined,
              size: 150,
              color: Colors.white.withOpacity(0.08),
            ),
          ),
          Positioned(
            left: -40,
            top: -56,
            child: Container(
              width: 140,
              height: 140,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(0.06),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    _CompanyLogo(
                      url: widget.logoUrl,
                      name: widget.companyName,
                      color: c.heroA,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _TypingText(
                            text: '$greeting,',
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              letterSpacing: 0.2,
                              color: Colors.white.withOpacity(0.85),
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            widget.companyName,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 19,
                              height: 1.15,
                              fontWeight: FontWeight.w800,
                              letterSpacing: -0.3,
                              color: Colors.white,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Column(
                      children: [
                        _CircleBtn(
                          icon: _isDarkMode
                              ? Icons.light_mode_rounded
                              : Icons.dark_mode_rounded,
                          onTap: () =>
                              setState(() => _isDarkMode = !_isDarkMode),
                        ),
                        const SizedBox(height: 8),
                        Stack(
                          clipBehavior: Clip.none,
                          children: [
                            _CircleBtn(
                              icon: Icons.notifications_none_rounded,
                              onTap: widget.onNotificationTap,
                            ),
                            Positioned(
                              right: 1,
                              top: 1,
                              child: Container(
                                width: 10,
                                height: 10,
                                decoration: BoxDecoration(
                                  color: const Color(0xFFFBBF24),
                                  shape: BoxShape.circle,
                                  border: Border.all(color: c.heroA, width: 2),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                FutureBuilder<Map<String, dynamic>>(
                  future: _future,
                  builder: (_, s) {
                    final d = s.data ?? _last;
                    final br = '${d?['business_reg_number'] ?? 'N/A'}';
                    return Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _HeroChip(icon: Icons.badge_outlined, text: 'BR $br'),
                        _HeroChip(
                          icon: Icons.calendar_today_outlined,
                          text: date,
                        ),
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ---------- OVERVIEW (donut + stats) ----------
  Widget _overview(_Pal c) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snap) {
        final filtering =
            _type.isNotEmpty || _status.isNotEmpty || _search.isNotEmpty;
        final d = filtering
            ? (_base ?? snap.data ?? _last)
            : (snap.data ?? _last);
        if (d == null) {
          if (snap.hasError) return const SizedBox.shrink();
          return Column(
            children: [
              _Skeleton(c: c, height: 150, radius: 20),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(child: _Skeleton(c: c, height: 72, radius: 18)),
                  const SizedBox(width: 10),
                  Expanded(child: _Skeleton(c: c, height: 72, radius: 18)),
                ],
              ),
            ],
          );
        }
        return Column(
          children: [
            _statusCard(d, c),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: _StatCard(
                    c: c,
                    icon: Icons.task_alt_rounded,
                    color: const Color(0xFF10B981),
                    value: _toInt(d['approved']),
                    label: 'Approved jobs',
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _StatCard(
                    c: c,
                    icon: Icons.engineering_rounded,
                    color: const Color(0xFF0284C7),
                    value: _toInt(d['workers']),
                    label: 'Registered workers',
                  ),
                ),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _statusCard(Map<String, dynamic> d, _Pal c) {
    final items = <(String, int, Color)>[
      ('Approved', _toInt(d['approved']), const Color(0xFF10B981)),
      ('Completed', _toInt(d['completed']), const Color(0xFF0D9488)),
      ('Pending', _toInt(d['pending']), const Color(0xFFF59E0B)),
      ('On Hold', _toInt(d['on_hold']), const Color(0xFF8B5CF6)),
      ('Rejected', _toInt(d['rejected']), const Color(0xFFE11D48)),
      ('Expired', _toInt(d['expired']), const Color(0xFF64748B)),
    ];
    final total = items.fold<int>(0, (a, b) => a + b.$2);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: _cardDeco(c, radius: 20),
      child: Row(
        children: [
          SizedBox(
            width: 108,
            height: 108,
            child: TweenAnimationBuilder<double>(
              key: ValueKey(items.map((e) => e.$2).join('-')),
              tween: Tween(begin: 0, end: 1),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, t, child) => CustomPaint(
                painter: _DonutPainter(
                  values: [for (final it in items) it.$2],
                  colors: [for (final it in items) it.$3],
                  progress: t,
                  track: c.border,
                ),
                child: child,
              ),
              child: Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _CountUp(
                      value: total,
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w800,
                        color: c.text,
                      ),
                    ),
                    Text(
                      'permits',
                      style: TextStyle(fontSize: 10.5, color: c.muted),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Wrap(
              runSpacing: 4,
              children: [
                for (final it in items)
                  SizedBox(
                    width: 108,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(8),
                      onTap: () => _setStatus(it.$1),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          vertical: 5,
                          horizontal: 2,
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 9,
                              height: 9,
                              decoration: BoxDecoration(
                                color: it.$3,
                                shape: BoxShape.circle,
                              ),
                            ),
                            const SizedBox(width: 7),
                            Expanded(
                              child: Text(
                                it.$1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  fontSize: 11.5,
                                  color: c.muted,
                                  fontWeight: _status == it.$1
                                      ? FontWeight.w800
                                      : FontWeight.w500,
                                ),
                              ),
                            ),
                            Text(
                              '${it.$2}',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                                color: c.text,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ---------- PERMITS ----------
  Widget _permitsSection(_Pal c) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Permits',
          style: TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.3,
            color: c.text,
          ),
        ),
        const SizedBox(height: 10),
        // Search
        Container(
          height: 46,
          decoration: BoxDecoration(
            color: c.card,
            border: Border.all(color: c.border),
            borderRadius: BorderRadius.circular(16),
          ),
          child: TextField(
            controller: _searchCtrl,
            style: TextStyle(fontSize: 13.5, color: c.text),
            cursorColor: c.accent,
            onChanged: (v) {
              setState(() {}); // refresh clear button
              _debounce?.cancel();
              _debounce = Timer(const Duration(milliseconds: 400), () {
                if (!mounted) return;
                setState(() {
                  _search = v.trim();
                  _page = 1;
                  _future = _fetch();
                });
              });
            },
            decoration: InputDecoration(
              hintText: 'Search permit number, project or supervisor',
              hintStyle: TextStyle(fontSize: 12.5, color: c.muted),
              prefixIcon: Icon(Icons.search_rounded, size: 20, color: c.muted),
              suffixIcon: _searchCtrl.text.isEmpty
                  ? null
                  : IconButton(
                      icon: Icon(Icons.close_rounded, size: 18, color: c.muted),
                      onPressed: () {
                        _searchCtrl.clear();
                        setState(() {
                          _search = '';
                          _page = 1;
                          _future = _fetch();
                        });
                      },
                    ),
              border: InputBorder.none,
              contentPadding: const EdgeInsets.symmetric(vertical: 13),
            ),
          ),
        ),
        const SizedBox(height: 12),
        // Status chips
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              _StatusChip(
                c: c,
                label: 'All',
                color: c.accent,
                selected: _status.isEmpty,
                onTap: () {
                  if (_status.isEmpty) return;
                  _setStatus(_status);
                },
              ),
              for (final s in _statuses)
                _StatusChip(
                  c: c,
                  label: s,
                  color: _statusColor(s),
                  dot: true,
                  selected: _status == s,
                  onTap: () => _setStatus(s),
                ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        // Dropdown filters
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              _PillDropdown<String>(
                c: c,
                value: _limit,
                items: {
                  for (final l in _limits)
                    l: l == 'all' ? 'Show all' : 'Show $l',
                },
                onChanged: (v) {
                  setState(() {
                    _limit = v ?? '5';
                    _page = 1;
                    _future = _fetch();
                  });
                },
              ),
              const SizedBox(width: 8),
              _PillDropdown<String>(
                c: c,
                value: _type,
                items: {'': 'All permit types', ..._types},
                onChanged: (v) {
                  setState(() {
                    _type = v ?? '';
                    _page = 1;
                    _future = _fetch();
                  });
                },
              ),
              if (_filtersActive) ...[
                const SizedBox(width: 8),
                InkWell(
                  onTap: _resetFilters,
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 8,
                    ),
                    decoration: BoxDecoration(
                      border: Border.all(color: c.accent.withOpacity(0.6)),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.restart_alt_rounded,
                          size: 15,
                          color: c.accent,
                        ),
                        const SizedBox(width: 5),
                        Text(
                          'Reset',
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w700,
                            color: c.accent,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 14),
        FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState == ConnectionState.waiting) {
              return Column(
                children: [
                  for (var i = 0; i < 3; i++) ...[
                    _Skeleton(c: c, height: 150, radius: 18),
                    const SizedBox(height: 10),
                  ],
                ],
              );
            }
            if (snap.hasError) {
              return _LoadError(
                c: c,
                message: '${snap.error}',
                onRetry: () => _reload(resetPage: false),
              );
            }
            final d = snap.data ?? {};
            final permits = (d['permits_list'] is List)
                ? (d['permits_list'] as List)
                      .whereType<Map<String, dynamic>>()
                      .toList()
                : <Map<String, dynamic>>[];
            final page = _atLeast1(_toInt(d['page']));
            final totalPages = _atLeast1(_toInt(d['total_pages']));

            if (permits.isEmpty) {
              return Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 32),
                decoration: _cardDeco(c, radius: 18),
                child: Column(
                  children: [
                    Icon(Icons.inbox_outlined, size: 38, color: c.muted),
                    const SizedBox(height: 10),
                    Text(
                      'No permits match these filters',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: c.text,
                      ),
                    ),
                    if (_filtersActive) ...[
                      const SizedBox(height: 6),
                      TextButton(
                        onPressed: _resetFilters,
                        child: Text(
                          'Clear filters',
                          style: TextStyle(color: c.accent),
                        ),
                      ),
                    ],
                  ],
                ),
              );
            }
            return Column(
              children: [
                for (final p in permits)
                  _PermitCard(
                    c: c,
                    permit: p,
                    onView: () => widget.onViewPermit?.call(p),
                  ),
                if (totalPages > 1 && _limit != 'all')
                  _pagination(page, totalPages, c),
              ],
            );
          },
        ),
      ],
    );
  }

  Widget _pagination(int page, int totalPages, _Pal c) {
    void go(int p) {
      setState(() {
        _page = p;
        _future = _fetch();
      });
    }

    final end = math.min(totalPages, math.max(1, page - 2) + 4);
    final start = math.max(1, end - 4);

    Widget btn(String label, {VoidCallback? onTap, bool active = false}) =>
        Padding(
          padding: const EdgeInsets.only(right: 6),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(10),
            child: Container(
              constraints: const BoxConstraints(minWidth: 36),
              alignment: Alignment.center,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: active ? c.accent : c.card,
                border: Border.all(color: active ? c.accent : c.border),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                label,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: active
                      ? Colors.white
                      : (onTap == null ? c.muted : c.text),
                ),
              ),
            ),
          ),
        );

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        children: [
          Text(
            'Page $page of $totalPages',
            style: TextStyle(fontSize: 11.5, color: c.muted),
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                btn('Prev', onTap: page > 1 ? () => go(page - 1) : null),
                for (var i = start; i <= end; i++)
                  btn(
                    '$i',
                    active: i == page,
                    onTap: i == page ? null : () => go(i),
                  ),
                btn(
                  'Next',
                  onTap: page < totalPages ? () => go(page + 1) : null,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

BoxDecoration _cardDeco(_Pal c, {double radius = 16}) => BoxDecoration(
  color: c.card,
  borderRadius: BorderRadius.circular(radius),
  border: Border.all(color: c.border),
  boxShadow: [
    BoxShadow(
      color: Colors.black.withOpacity(c.dark ? 0.25 : 0.04),
      blurRadius: 14,
      offset: const Offset(0, 5),
    ),
  ],
);

// ======================= PERMIT CARD =======================
class _PermitCard extends StatelessWidget {
  const _PermitCard({
    required this.c,
    required this.permit,
    required this.onView,
  });

  final _Pal c;
  final Map<String, dynamic> permit;
  final VoidCallback onView;

  String _formatCategory(String raw) => raw
      .replaceAll('_', ' ')
      .split(' ')
      .map((w) => w.isEmpty ? w : w[0].toUpperCase() + w.substring(1))
      .join(' ');

  IconData _categoryIcon(String cat) {
    final s = cat.toLowerCase();
    if (s.contains('hot')) return Icons.local_fire_department_outlined;
    if (s.contains('height')) return Icons.stairs_outlined;
    return Icons.assignment_outlined;
  }

  @override
  Widget build(BuildContext context) {
    final status = '${permit['status'] ?? 'Pending'}';
    final sc = _statusColor(status);
    final category = _formatCategory('${permit['work_category'] ?? 'N/A'}');

    final createdRaw = '${permit['declaration_date'] ?? ''}';
    final created = DateTime.tryParse(createdRaw.replaceFirst(' ', 'T'));
    double progress = 0;
    String timeText = '-';
    Color timeColor = c.muted;
    if (created != null) {
      const total = Duration(days: 7);
      final remaining = created.add(total).difference(DateTime.now());
      if (remaining.inSeconds <= 0) {
        timeText = 'Expired';
        timeColor = const Color(0xFFE11D48);
      } else {
        final d = remaining.inDays;
        final h = remaining.inHours % 24;
        timeText = '${d > 0 ? '${d}d ' : ''}${h}h left';
        progress = (remaining.inSeconds / total.inSeconds).clamp(0.0, 1.0);
        timeColor = progress > 0.5
            ? const Color(0xFF10B981)
            : progress > 0.2
            ? const Color(0xFFF59E0B)
            : const Color(0xFFE11D48);
      }
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: _cardDeco(c, radius: 18),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: Material(
          color: c.card,
          child: InkWell(
            onTap: onView,
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Container(width: 5, color: sc),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 13, 12, 10),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  '${permit['permit_number'] ?? 'N/A'}',
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    fontWeight: FontWeight.w800,
                                    fontSize: 14.5,
                                    letterSpacing: -0.1,
                                    color: c.accent,
                                  ),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 9,
                                  vertical: 4,
                                ),
                                decoration: BoxDecoration(
                                  color: sc.withOpacity(0.14),
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      width: 6,
                                      height: 6,
                                      decoration: BoxDecoration(
                                        color: sc,
                                        shape: BoxShape.circle,
                                      ),
                                    ),
                                    const SizedBox(width: 5),
                                    Text(
                                      status,
                                      style: TextStyle(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w800,
                                        color: sc,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 9),
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                _categoryIcon(category),
                                size: 15,
                                color: c.muted,
                              ),
                              const SizedBox(width: 6),
                              Text(
                                category,
                                style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w700,
                                  color: c.text,
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          _row(
                            Icons.folder_outlined,
                            'Project',
                            '${permit['cat_project'] ?? 'N/A'}',
                          ),
                          const SizedBox(height: 4),
                          _row(
                            Icons.person_outline_rounded,
                            'Supervisor',
                            '${permit['supervisor_name'] ?? 'N/A'}',
                          ),
                          const SizedBox(height: 4),
                          _row(
                            Icons.event_outlined,
                            'Created',
                            createdRaw.isEmpty ? 'N/A' : createdRaw,
                          ),
                          const SizedBox(height: 10),
                          ClipRRect(
                            borderRadius: BorderRadius.circular(4),
                            child: LinearProgressIndicator(
                              value: progress,
                              minHeight: 5,
                              backgroundColor: c.border,
                              valueColor: AlwaysStoppedAnimation(timeColor),
                            ),
                          ),
                          const SizedBox(height: 6),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Row(
                                children: [
                                  Icon(
                                    Icons.timer_outlined,
                                    size: 14,
                                    color: timeColor,
                                  ),
                                  const SizedBox(width: 4),
                                  Text(
                                    timeText,
                                    style: TextStyle(
                                      fontSize: 11.5,
                                      fontWeight: FontWeight.w700,
                                      color: timeColor,
                                    ),
                                  ),
                                ],
                              ),
                              Row(
                                children: [
                                  Text(
                                    'View details',
                                    style: TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.w700,
                                      color: c.accent,
                                    ),
                                  ),
                                  Icon(
                                    Icons.chevron_right_rounded,
                                    size: 18,
                                    color: c.accent,
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _row(IconData icon, String label, String value) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Icon(icon, size: 14, color: c.muted),
      const SizedBox(width: 6),
      Text('$label: ', style: TextStyle(fontSize: 11.5, color: c.muted)),
      Expanded(
        child: Text(
          value,
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: c.text,
          ),
        ),
      ),
    ],
  );
}

// ======================= SMALL WIDGETS =======================
class _TypingText extends StatefulWidget {
  const _TypingText({required this.text, required this.style});
  final String text;
  final TextStyle style;

  @override
  State<_TypingText> createState() => _TypingTextState();
}

class _TypingTextState extends State<_TypingText> {
  String _displayedText = '';
  Timer? _timer;
  int _currentIndex = 0;

  @override
  void initState() {
    super.initState();
    _startTyping();
  }

  @override
  void didUpdateWidget(covariant _TypingText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.text != widget.text) _startTyping();
  }

  void _startTyping() {
    _timer?.cancel();
    _currentIndex = 0;
    _displayedText = '';
    _timer = Timer.periodic(const Duration(milliseconds: 70), (timer) {
      if (_currentIndex < widget.text.length) {
        setState(() {
          _displayedText += widget.text[_currentIndex];
          _currentIndex++;
        });
      } else {
        timer.cancel();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      Text(_displayedText.isEmpty ? ' ' : _displayedText, style: widget.style);
}

class _DonutPainter extends CustomPainter {
  _DonutPainter({
    required this.values,
    required this.colors,
    required this.progress,
    required this.track,
  });

  final List<int> values;
  final List<Color> colors;
  final double progress;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 12.0;
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.butt;

    final center = size.center(Offset.zero);
    final radius = (size.width - stroke) / 2;
    final rect = Rect.fromCircle(center: center, radius: radius);

    paint.color = track;
    canvas.drawCircle(center, radius, paint);

    final total = values.fold<int>(0, (a, b) => a + b);
    if (total == 0) return;

    final nonZero = values.where((v) => v > 0).length;
    final gap = nonZero > 1 ? 0.07 : 0.0;
    double start = -math.pi / 2;

    for (var i = 0; i < values.length; i++) {
      if (values[i] <= 0) continue;
      final full = values[i] / total * 2 * math.pi;
      final sweep = math.max(0.0, full - gap) * progress;
      paint.color = colors[i];
      canvas.drawArc(rect, start + gap / 2, sweep, false, paint);
      start += full;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter old) =>
      old.progress != progress || old.values != values || old.track != track;
}

class _CountUp extends StatelessWidget {
  const _CountUp({required this.value, required this.style});
  final int value;
  final TextStyle style;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<int>(
    tween: IntTween(begin: 0, end: value),
    duration: const Duration(milliseconds: 800),
    curve: Curves.easeOutCubic,
    builder: (context, v, child) => Text('$v', style: style),
  );
}

class _CompanyLogo extends StatelessWidget {
  const _CompanyLogo({
    required this.url,
    required this.name,
    required this.color,
  });

  final String? url;
  final String name;
  final Color color;

  String get _initials {
    final parts = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((w) => w.isNotEmpty)
        .toList();
    if (parts.isEmpty) return '?';
    final a = parts.first[0];
    final b = parts.length > 1 ? parts[1][0] : '';
    return (a + b).toUpperCase();
  }

  Widget _fallback() => Center(
    child: Text(
      _initials,
      style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final u = url?.trim() ?? '';
    Widget child;
    if (u.isEmpty) {
      child = _fallback();
    } else if (u.startsWith('assets/')) {
      child = Image.asset(
        u,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) => _fallback(),
      );
    } else {
      child = Image.network(
        u,
        fit: BoxFit.contain,
        errorBuilder: (_, __, ___) => _fallback(),
      );
    }
    return Container(
      width: 104,
      height: 72,
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.18),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: child,
    );
  }
}

class _CircleBtn extends StatelessWidget {
  const _CircleBtn({required this.icon, required this.onTap});
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white.withOpacity(0.16),
    shape: const CircleBorder(),
    child: InkWell(
      customBorder: const CircleBorder(),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(9),
        child: Icon(icon, size: 19, color: Colors.white),
      ),
    ),
  );
}

class _HeroChip extends StatelessWidget {
  const _HeroChip({required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
    decoration: BoxDecoration(
      color: Colors.white.withOpacity(0.16),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 13, color: Colors.white.withOpacity(0.9)),
        const SizedBox(width: 6),
        Text(
          text,
          style: const TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: Colors.white,
          ),
        ),
      ],
    ),
  );
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.c,
    required this.icon,
    required this.color,
    required this.value,
    required this.label,
  });

  final _Pal c;
  final IconData icon;
  final Color color;
  final int value;
  final String label;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: _cardDeco(c, radius: 18),
    child: Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: color.withOpacity(0.15),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: color, size: 21),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _CountUp(
                value: value,
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                  color: c.text,
                ),
              ),
              Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w500,
                  color: c.muted,
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({
    required this.c,
    required this.label,
    required this.color,
    required this.selected,
    required this.onTap,
    this.dot = false,
  });

  final _Pal c;
  final String label;
  final Color color;
  final bool selected;
  final bool dot;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? color : c.card,
          border: Border.all(color: selected ? color : c.border),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (dot && !selected) ...[
              Container(
                width: 7,
                height: 7,
                decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              ),
              const SizedBox(width: 6),
            ],
            Text(
              label,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: selected ? Colors.white : c.text,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _PillDropdown<T> extends StatelessWidget {
  const _PillDropdown({
    required this.c,
    required this.value,
    required this.items,
    required this.onChanged,
  });

  final _Pal c;
  final T value;
  final Map<T, String> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12),
    decoration: BoxDecoration(
      color: c.card,
      border: Border.all(color: c.border),
      borderRadius: BorderRadius.circular(20),
    ),
    child: DropdownButtonHideUnderline(
      child: DropdownButton<T>(
        value: items.containsKey(value) ? value : items.keys.first,
        isDense: true,
        dropdownColor: c.card,
        borderRadius: BorderRadius.circular(14),
        icon: Icon(Icons.keyboard_arrow_down_rounded, size: 18, color: c.muted),
        style: TextStyle(fontSize: 12, color: c.text),
        items: [
          for (final e in items.entries)
            DropdownMenuItem<T>(
              value: e.key,
              child: Text(e.value, style: TextStyle(color: c.text)),
            ),
        ],
        onChanged: onChanged,
      ),
    ),
  );
}

class _Skeleton extends StatefulWidget {
  const _Skeleton({
    required this.c,
    required this.height,
    required this.radius,
  });
  final _Pal c;
  final double height;
  final double radius;

  @override
  State<_Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<_Skeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => FadeTransition(
    opacity: Tween<double>(begin: 0.45, end: 1).animate(_ctrl),
    child: Container(
      width: double.infinity,
      height: widget.height,
      decoration: BoxDecoration(
        color: widget.c.border.withOpacity(widget.c.dark ? 0.6 : 0.8),
        borderRadius: BorderRadius.circular(widget.radius),
      ),
    ),
  );
}

class _LoadError extends StatelessWidget {
  const _LoadError({
    required this.c,
    required this.message,
    required this.onRetry,
  });

  final _Pal c;
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(18),
    decoration: _cardDeco(c, radius: 18),
    child: Column(
      children: [
        Icon(Icons.cloud_off_outlined, size: 38, color: c.accent),
        const SizedBox(height: 10),
        Text(
          'Could not load permits',
          style: TextStyle(fontWeight: FontWeight.w800, color: c.text),
        ),
        const SizedBox(height: 6),
        Text(
          message,
          textAlign: TextAlign.center,
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(color: c.muted, fontSize: 12),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: onRetry,
          style: OutlinedButton.styleFrom(
            foregroundColor: c.text,
            side: BorderSide(color: c.border),
          ),
          icon: Icon(Icons.refresh_rounded, color: c.accent),
          label: const Text('Try again'),
        ),
      ],
    ),
  );
}
