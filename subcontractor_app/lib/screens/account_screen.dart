import 'package:flutter/material.dart';
import '../services/subcontractor_api.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({
    super.key,
    required this.api,
    required this.companyName,
    required this.username,
  });

  final SubcontractorApi api;
  final String companyName;
  final String username;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 110),
        children: [
          const CircleAvatar(radius: 40, child: Icon(Icons.person, size: 40)),
          const SizedBox(height: 16),
          Center(
            child: Text(
              username,
              style: Theme.of(context).textTheme.titleLarge,
            ),
          ),
          Center(child: Text(companyName)),
          const SizedBox(height: 24),
          const Card(
            child: ListTile(leading: Icon(Icons.logout), title: Text('Logout')),
          ),
        ],
      ),
    );
  }
}
