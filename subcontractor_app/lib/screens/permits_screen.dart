import 'dart:async';
import 'package:flutter/material.dart';
import '../services/subcontractor_api.dart';

// ======================= PALETTE (Same as Dashboard) =======================
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
class PermitsScreen extends StatefulWidget {
  const PermitsScreen({
    super.key,
    required this.api,
    required this.companyName,
    this.logoUrl = 'assets/images/hayleys_logo.png',
    this.onNotificationTap,
  });

  final SubcontractorApi api;
  final String companyName;
  final String? logoUrl;
  final VoidCallback? onNotificationTap;

  @override
  State<PermitsScreen> createState() => _PermitsScreenState();
}

class _PermitsScreenState extends State<PermitsScreen> {
  static const _filters = [
    'All',
    'Pending',
    'Approved',
    'Rejected',
    'Completed',
  ];

  PermitHistory? _data;
  bool _loading = true;
  String? _error;
  String _filter = 'All';

  bool _isDarkMode = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _data == null;
      _error = null;
    });
    try {
      final d = await widget.api.fetchPermitHistory(
        companyName: widget.companyName,
      );
      if (!mounted) return;
      setState(() {
        _data = d;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  String get _today {
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const months = [
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
    return '${days[n.weekday - 1]}, ${months[n.month - 1]} ${n.day}, ${n.year}';
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
          onRefresh: _load,
          child: _buildBody(c),
        ),
      ),
    );
  }

  Widget _buildBody(_Pal c) {
    if (_loading) {
      return Center(child: CircularProgressIndicator(color: c.accent));
    }
    if (_error != null && _data == null) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 80),
          Icon(Icons.cloud_off_outlined, size: 48, color: c.accent),
          const SizedBox(height: 12),
          Text(
            'Could not load permit history',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w800,
              color: c.text,
              fontSize: 16,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            _error!,
            textAlign: TextAlign.center,
            style: TextStyle(color: c.muted, fontSize: 12),
          ),
          const SizedBox(height: 16),
          Center(
            child: OutlinedButton.icon(
              onPressed: _load,
              style: OutlinedButton.styleFrom(
                foregroundColor: c.text,
                side: BorderSide(color: c.border),
              ),
              icon: Icon(Icons.refresh_rounded, color: c.accent),
              label: const Text('Try Again'),
            ),
          ),
        ],
      );
    }

    final data = _data!;
    final list = _filter == 'All'
        ? data.permits
        : data.permits
              .where((p) => p.status.toLowerCase() == _filter.toLowerCase())
              .toList();

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 120),
      children: [
        _hero(c, data),
        const SizedBox(height: 16),
        // Filter Chips
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final f in _filters)
                _StatusChip(
                  c: c,
                  label: f,
                  color: f == 'All' ? c.accent : _statusColor(f),
                  selected: _filter == f,
                  dot: f != 'All',
                  onTap: () => setState(() => _filter = f),
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            Icon(Icons.format_list_bulleted_rounded, size: 18, color: c.accent),
            const SizedBox(width: 6),
            Text(
              'Complete Permits & Job History',
              style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: 14,
                color: c.text,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (list.isEmpty)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 36),
            alignment: Alignment.center,
            decoration: _cardDeco(c, radius: 18),
            child: Column(
              children: [
                Icon(Icons.inbox_outlined, size: 38, color: c.muted),
                const SizedBox(height: 8),
                Text(
                  'No permit history records found.',
                  style: TextStyle(
                    color: c.muted,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          )
        else
          for (final p in list) _permitCard(p, c),
      ],
    );
  }

  // ---------- HERO HEADER (Dashboard style with Date & Pending Count inside) ----------
  Widget _hero(_Pal c, PermitHistory data) {
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
              Icons.history_edu_rounded,
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
                            text: 'Permit History,',
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
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _HeroChip(
                      icon: Icons.pending_actions_outlined,
                      text: 'Pending: ${data.pendingCount}',
                    ),
                    _HeroChip(
                      icon: Icons.calendar_today_outlined,
                      text: _today,
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ---------- PERMIT CARD ----------
  Widget _permitCard(Permit p, _Pal c) {
    final sc = _statusColor(p.status);

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: _cardDeco(c, radius: 18),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: Material(
          color: c.card,
          child: InkWell(
            onTap: () => _showDetails(p, c),
            child: IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Container(width: 5, color: sc),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 13, 12, 12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  p.number,
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
                                      p.status,
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
                          const SizedBox(height: 10),
                          _row(
                            Icons.assignment_outlined,
                            'Permit Type',
                            p.category,
                            c,
                          ),
                          const SizedBox(height: 4),
                          _row(Icons.folder_outlined, 'Project', p.project, c),
                          const SizedBox(height: 4),
                          _row(
                            Icons.person_outline_rounded,
                            'Supervisor',
                            p.supervisor,
                            c,
                          ),
                          const SizedBox(height: 4),
                          _row(Icons.event_outlined, 'Created Date', p.date, c),
                          const SizedBox(height: 10),
                          Align(
                            alignment: Alignment.centerRight,
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
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

  Widget _row(IconData icon, String label, String value, _Pal c) {
    return Row(
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

  void _showDetails(Permit p, _Pal c) {
    showModalBottomSheet(
      context: context,
      backgroundColor: c.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: c.border,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              p.number,
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w800,
                color: c.accent,
              ),
            ),
            const SizedBox(height: 14),
            _row(Icons.assignment_outlined, 'Permit Type', p.category, c),
            const SizedBox(height: 8),
            _row(Icons.folder_outlined, 'Project', p.project, c),
            const SizedBox(height: 8),
            _row(Icons.person_outline_rounded, 'Supervisor', p.supervisor, c),
            const SizedBox(height: 8),
            _row(Icons.verified_outlined, 'Status', p.status, c),
            const SizedBox(height: 8),
            _row(Icons.event_outlined, 'Created Date', p.date, c),
          ],
        ),
      ),
    );
  }
}

// ======================= REUSABLE COMPONENTS =======================

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

  void _startTyping() {
    _timer?.cancel();
    _currentIndex = 0;
    _displayedText = '';
    _timer = Timer.periodic(const Duration(milliseconds: 70), (timer) {
      if (_currentIndex < widget.text.length) {
        if (mounted) {
          setState(() {
            _displayedText += widget.text[_currentIndex];
            _currentIndex++;
          });
        }
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
