import 'package:flutter_test/flutter_test.dart';
import 'package:subcontractor_app/services/subcontractor_api.dart';

void main() {
  test('API base URL is a valid absolute URL', () {
    final uri = Uri.parse(SubcontractorApi.baseUrl);

    expect(uri.hasScheme, isTrue);
    expect(uri.hasAuthority, isTrue);
  });
}
