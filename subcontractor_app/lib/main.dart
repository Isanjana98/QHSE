import 'package:flutter/material.dart';

import 'screens/login_screen.dart';

void main() {
  runApp(const QhseApp());
}

class QhseApp extends StatelessWidget {
  const QhseApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'QHSE Subcontractor',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF147D72)),
        scaffoldBackgroundColor: const Color(0xFFF4F7F6),
      ),
      home: const LoginScreen(),
    );
  }
}
