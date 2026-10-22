import 'package:flutter/material.dart';

import '../services/subcontractor_api.dart';
import 'account_screen.dart';
import 'dashboard_screen.dart';
import 'floating_nav_bar.dart';
import 'inspections_screen.dart';
import 'permits_screen.dart';
import 'workers_screen.dart';

class AppShell extends StatefulWidget {
  const AppShell({
    super.key,
    required this.api,
    required this.companyName,
    required this.username,
  });

  final SubcontractorApi api;
  final String companyName;
  final String username;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _selectedIndex = 0;

  @override
  Widget build(BuildContext context) {
    // 0 Home, 1 Permits, 2 Inspections, 3 Workers, 4 Account
    final pages = [
      DashboardScreen(api: widget.api, companyName: widget.companyName),
      PermitsScreen(api: widget.api, companyName: widget.companyName),
      InspectionsScreen(
        api: widget.api,
        companyName: widget.companyName, // <-- මෙතනට මේක add කරන්න
      ),
      WorkersScreen(api: widget.api),
      AccountScreen(
        api: widget.api,
        companyName: widget.companyName,
        username: widget.username,
      ),
    ];

    return Scaffold(
      extendBody: true,
      body: IndexedStack(index: _selectedIndex, children: pages),
      bottomNavigationBar: FloatingNavBar(
        currentIndex: _selectedIndex,
        onTap: (index) => setState(() => _selectedIndex = index),
      ),
    );
  }
}
