import 'dart:async';
import 'dart:ui';
import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';

import '../services/subcontractor_api.dart';
import 'app_shell.dart';
import 'portal_screen.dart';
// "show" avoids the "PortalScreen is imported from both files" error.
import 'register_screen.dart' show RegisterScreen;

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _api = SubcontractorApi();
  final _handledTickets = <String>{};
  StreamSubscription<Uri>? _appLinkSubscription;

  bool _loading = false;
  bool _obscurePassword = true;
  String? _error;

  int _currentSlideIndex = 0;
  Timer? _sliderTimer;

  final List<Map<String, String>> _slides = [
    {
      'eyebrow': 'Safety & Compliance',
      'title': 'Operational excellence across every site',
      'desc':
          'Streamlining hazard controls, safety audits, and real-time incident tracking.',
      'image': 'assets/images/p.jpg',
    },
    {
      'eyebrow': 'Quality Assurance',
      'title': 'Maintaining the highest industry standards',
      'desc':
          'Standardized inspection workflows ensuring quality and regulatory adherence.',
      'image': 'assets/images/q.jpg',
    },
    {
      'eyebrow': 'Environmental Management',
      'title': 'Sustainable and responsible operations',
      'desc': 'Proactive risk assessment and environmental impact monitoring.',
      'image': 'assets/images/r.jpg',
    },
  ];

  @override
  void initState() {
    super.initState();
    _startSliderTimer();
    _initializeAppLinks();
  }

  Future<void> _initializeAppLinks() async {
    final appLinks = AppLinks();
    _appLinkSubscription = appLinks.uriLinkStream.listen(
      (uri) => unawaited(_handleAppLink(uri)),
    );
    final initialUri = await appLinks.getInitialLink();
    if (initialUri != null) await _handleAppLink(initialUri);
  }

  Future<void> _handleAppLink(Uri uri) async {
    if (uri.scheme != 'qhse-subcontractor' || uri.host != 'login') return;
    final ticket = uri.queryParameters['ticket'] ?? '';
    if (!RegExp(r'^[a-f0-9]{64}$').hasMatch(ticket) ||
        !_handledTickets.add(ticket)) {
      return;
    }
    if (!mounted) return;

    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final account = await _api.loginWithHandoffTicket(ticket);
      if (!mounted) return;
      if (account['role'] != 'subcontractor') {
        throw const ApiException(
          'This mobile app sign-in is for subcontractors.',
        );
      }
      _openAccount(account);
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (error) {
      if (mounted) setState(() => _error = 'App sign in failed: $error');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _startSliderTimer() {
    _sliderTimer?.cancel();
    _sliderTimer = Timer.periodic(const Duration(seconds: 5), (timer) {
      if (mounted) {
        setState(() {
          _currentSlideIndex = (_currentSlideIndex + 1) % _slides.length;
        });
      }
    });
  }

  @override
  void dispose() {
    _sliderTimer?.cancel();
    _appLinkSubscription?.cancel();
    _usernameController.dispose();
    _passwordController.dispose();
    // NOTE: do NOT call _api.dispose() here. The same _api instance is
    // passed to AppShell and must stay open.
    super.dispose();
  }

  Future<void> _signIn() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final account = await _api.login(
        _usernameController.text.trim(),
        _passwordController.text,
      );
      if (!mounted) return;
      _openAccount(account);
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (e) {
      if (mounted) {
        setState(() => _error = 'Sign in failed: $e');
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openAccount(Map<String, dynamic> account) {
    final role = (account['role'] ?? 'subcontractor').toString();
    if (role == 'subcontractor') {
      final companyName = _decode(
        (account['company_name'] ?? 'Subcontractor').toString(),
      );
      final username = (account['username'] ?? _usernameController.text.trim())
          .toString();
      Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(
          builder: (_) =>
              AppShell(api: _api, companyName: companyName, username: username),
        ),
      );
      return;
    }

    // Admin / Supervisor: open the web dashboard inside the app.
    final webToken = (account['web_token'] ?? '').toString();
    Navigator.of(context).pushReplacement(
      MaterialPageRoute<void>(
        builder: (_) => PortalScreen(
          api: _api,
          url: '${SubcontractorApi.baseUrl}/mobile_web_login.php?t=$webToken',
        ),
      ),
    );
  }

  String _decode(String s) => s
      .replaceAll('&amp;', '&')
      .replaceAll('&lt;', '<')
      .replaceAll('&gt;', '>')
      .replaceAll('&quot;', '"')
      .replaceAll('&#039;', "'");

  @override
  Widget build(BuildContext context) {
    final currentSlide = _slides[_currentSlideIndex];
    final screenWidth = MediaQuery.of(context).size.width;
    final isWideScreen = screenWidth > 768;

    return Scaffold(
      backgroundColor: const Color(0xFF140A03),
      body: Stack(
        children: [
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 1200),
            child: Container(
              key: ValueKey<int>(_currentSlideIndex),
              decoration: BoxDecoration(
                image: DecorationImage(
                  image: AssetImage(currentSlide['image']!),
                  fit: BoxFit.cover,
                  colorFilter: ColorFilter.mode(
                    Colors.black.withOpacity(0.50),
                    BlendMode.darken,
                  ),
                ),
              ),
            ),
          ),
          Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  const Color(0xFF140A03).withOpacity(0.85),
                  const Color(0xFF140A03).withOpacity(0.35),
                  const Color(0xFF140A03).withOpacity(0.85),
                ],
                begin: Alignment.centerLeft,
                end: Alignment.centerRight,
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Expanded(
                  child: Align(
                    alignment: isWideScreen
                        ? Alignment.centerRight
                        : Alignment.center,
                    child: SingleChildScrollView(
                      padding: EdgeInsets.only(
                        left: 24,
                        right: isWideScreen ? 60 : 24,
                        top: 40,
                        bottom: 24,
                      ),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 420),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const Text(
                              'QHSE EN MANAGEMENT SYSTEM',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Color(0xFFF2B545),
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 1.4,
                              ),
                            ),
                            const SizedBox(height: 16),
                            ClipRRect(
                              borderRadius: BorderRadius.circular(24),
                              child: BackdropFilter(
                                filter: ImageFilter.blur(
                                  sigmaX: 18,
                                  sigmaY: 18,
                                ),
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 28,
                                    vertical: 32,
                                  ),
                                  decoration: BoxDecoration(
                                    color: const Color(
                                      0xFF3A2211,
                                    ).withOpacity(0.55),
                                    borderRadius: BorderRadius.circular(24),
                                    border: Border.all(
                                      color: const Color(
                                        0xFFF2B545,
                                      ).withOpacity(0.30),
                                      width: 1.2,
                                    ),
                                  ),
                                  child: Form(
                                    key: _formKey,
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.stretch,
                                      children: [
                                        const Text(
                                          'Welcome back',
                                          textAlign: TextAlign.center,
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 26,
                                            fontWeight: FontWeight.w800,
                                          ),
                                        ),
                                        const SizedBox(height: 6),
                                        const Text(
                                          'Sign in to continue to your dashboard',
                                          textAlign: TextAlign.center,
                                          style: TextStyle(
                                            color: Color(0xFFF3E6D4),
                                            fontSize: 13,
                                          ),
                                        ),
                                        const SizedBox(height: 26),
                                        const Text(
                                          'Username',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w600,
                                            fontSize: 13,
                                          ),
                                        ),
                                        const SizedBox(height: 6),
                                        _buildInputField(
                                          controller: _usernameController,
                                          hintText: 'Enter your username',
                                          icon: Icons.person_outline,
                                          textInputAction: TextInputAction.next,
                                          validator: (val) =>
                                              val == null || val.trim().isEmpty
                                              ? 'Enter your username'
                                              : null,
                                        ),
                                        const SizedBox(height: 16),
                                        const Text(
                                          'Password',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontWeight: FontWeight.w600,
                                            fontSize: 13,
                                          ),
                                        ),
                                        const SizedBox(height: 6),
                                        _buildInputField(
                                          controller: _passwordController,
                                          hintText: 'Enter your password',
                                          icon: Icons.lock_outline,
                                          obscureText: _obscurePassword,
                                          onFieldSubmitted: (_) => _signIn(),
                                          suffixIcon: IconButton(
                                            icon: Icon(
                                              _obscurePassword
                                                  ? Icons.visibility_outlined
                                                  : Icons
                                                        .visibility_off_outlined,
                                              color: const Color(0xFFFF7A1A),
                                              size: 20,
                                            ),
                                            onPressed: () => setState(
                                              () => _obscurePassword =
                                                  !_obscurePassword,
                                            ),
                                          ),
                                          validator: (val) =>
                                              val == null || val.isEmpty
                                              ? 'Enter your password'
                                              : null,
                                        ),
                                        if (_error != null) ...[
                                          const SizedBox(height: 14),
                                          Container(
                                            padding: const EdgeInsets.symmetric(
                                              horizontal: 12,
                                              vertical: 10,
                                            ),
                                            decoration: BoxDecoration(
                                              color: const Color(0xFFFEF2F2),
                                              borderRadius:
                                                  BorderRadius.circular(10),
                                              border: Border.all(
                                                color: const Color(0xFFFECACA),
                                              ),
                                            ),
                                            child: Row(
                                              children: [
                                                const Icon(
                                                  Icons.error_outline_rounded,
                                                  color: Color(0xFFDC2626),
                                                  size: 18,
                                                ),
                                                const SizedBox(width: 8),
                                                Expanded(
                                                  child: Text(
                                                    _error!,
                                                    style: const TextStyle(
                                                      color: Color(0xFFDC2626),
                                                      fontSize: 12,
                                                      fontWeight:
                                                          FontWeight.w500,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                        ],
                                        const SizedBox(height: 24),
                                        ElevatedButton(
                                          onPressed: _loading ? null : _signIn,
                                          style: ElevatedButton.styleFrom(
                                            backgroundColor: const Color(
                                              0xFFFF5500,
                                            ),
                                            foregroundColor: Colors.white,
                                            minimumSize: const Size.fromHeight(
                                              46,
                                            ),
                                            shape: RoundedRectangleBorder(
                                              borderRadius:
                                                  BorderRadius.circular(50),
                                            ),
                                          ),
                                          child: _loading
                                              ? const SizedBox.square(
                                                  dimension: 20,
                                                  child:
                                                      CircularProgressIndicator(
                                                        strokeWidth: 2,
                                                        color: Colors.white,
                                                      ),
                                                )
                                              : const Text(
                                                  'Sign In',
                                                  style: TextStyle(
                                                    fontSize: 15,
                                                    fontWeight: FontWeight.w700,
                                                  ),
                                                ),
                                        ),
                                        const SizedBox(height: 10),
                                        TextButton(
                                          onPressed: _loading
                                              ? null
                                              : () => Navigator.of(context).push(
                                                  MaterialPageRoute<void>(
                                                    // no "const" here, so it
                                                    // compiles either way
                                                    builder: (_) =>
                                                        RegisterScreen(),
                                                  ),
                                                ),
                                          child: const Text(
                                            'New subcontractor? Register here',
                                            style: TextStyle(
                                              color: Color(0xFFF2B545),
                                              fontSize: 13,
                                              fontWeight: FontWeight.w600,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
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

  Widget _buildInputField({
    required TextEditingController controller,
    required String hintText,
    required IconData icon,
    bool obscureText = false,
    TextInputAction? textInputAction,
    void Function(String)? onFieldSubmitted,
    Widget? suffixIcon,
    String? Function(String?)? validator,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF140B04).withOpacity(0.60),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: const Color(0xFFFF7A1A).withOpacity(0.40),
          width: 1.5,
        ),
      ),
      child: TextFormField(
        controller: controller,
        obscureText: obscureText,
        textInputAction: textInputAction,
        onFieldSubmitted: onFieldSubmitted,
        style: const TextStyle(color: Colors.white, fontSize: 14),
        decoration: InputDecoration(
          hintText: hintText,
          hintStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
          prefixIcon: Icon(icon, color: const Color(0xFFFF7A1A), size: 20),
          suffixIcon: suffixIcon,
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 14,
            vertical: 12,
          ),
        ),
        validator: validator,
      ),
    );
  }
}
