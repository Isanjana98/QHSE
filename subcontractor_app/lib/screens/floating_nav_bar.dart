import 'package:flutter/material.dart';

/// Floating bottom bar with all four corners rounded.
/// Full width of the screen. 5 tabs: Home, Permits, Inspections, Workers, Account.
class FloatingNavBar extends StatelessWidget {
  const FloatingNavBar({
    super.key,
    required this.currentIndex,
    required this.onTap,
  });

  final int currentIndex;
  final ValueChanged<int> onTap;

  static const _items = <(IconData, IconData, String)>[
    (Icons.space_dashboard_outlined, Icons.space_dashboard_rounded, 'Home'),
    (Icons.assignment_outlined, Icons.assignment_rounded, 'Permits'),
    (Icons.handyman_outlined, Icons.handyman_rounded, 'Inspections'),
    (Icons.people_outline_rounded, Icons.people_rounded, 'Workers'),
    (Icons.person_outline_rounded, Icons.person_rounded, 'Account'),
  ];

  // Header lanti green mariyu orange colors ikkada use chesaamu
  static const _heroA = Color(
    0xFF064E3B,
  ); // Dark Green (from Dashboard header)[cite: 2]
  static const _heroB = Color(
    0xFFEA580C,
  ); // Orange (from Dashboard header)[cite: 2]
  static const _selected = Color(0xFF86EFAC);

  @override
  Widget build(BuildContext context) {
    const radius = BorderRadius.all(Radius.circular(28));

    return SafeArea(
      top: false,
      child: Padding(
        // gap: left 8, right 8, bottom 10 (full width, still floating)
        padding: const EdgeInsets.fromLTRB(8, 0, 8, 10),
        child: SizedBox(
          width: double.infinity, // <-- full width
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: radius,
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.30),
                  blurRadius: 18,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Material(
              color: Colors.transparent,
              child: Ink(
                decoration: BoxDecoration(
                  // Header lo unna green and orange colors tho gradient tint
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      _heroA.withOpacity(0.95),
                      _heroB.withOpacity(0.75), // Orange tint shade
                    ],
                  ),
                  borderRadius: radius,
                  border: Border.all(color: Colors.white.withOpacity(0.15)),
                ),
                child: SizedBox(
                  height: 70,
                  child: Row(
                    children: [
                      for (var i = 0; i < _items.length; i++)
                        Expanded(
                          child: InkResponse(
                            onTap: () => onTap(i),
                            radius: 38,
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  i == currentIndex
                                      ? _items[i].$2
                                      : _items[i].$1,
                                  size: 25,
                                  color: i == currentIndex
                                      ? _selected
                                      : Colors.white.withOpacity(0.70),
                                ),
                                const SizedBox(height: 3),
                                Text(
                                  _items[i].$3,
                                  style: TextStyle(
                                    fontSize: 10.5,
                                    fontWeight: i == currentIndex
                                        ? FontWeight.w700
                                        : FontWeight.w500,
                                    color: i == currentIndex
                                        ? _selected
                                        : Colors.white.withOpacity(0.70),
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
        ),
      ),
    );
  }
}
