import 'package:flutter/material.dart';
import '../services/subcontractor_api.dart';

class WorkersScreen extends StatelessWidget {
  const WorkersScreen({super.key, required this.api});

  final SubcontractorApi api;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Workers')),
      body: ListView.builder(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 110),
        itemCount: 5,
        itemBuilder: (context, i) => Card(
          child: ListTile(
            leading: const CircleAvatar(child: Icon(Icons.person)),
            title: Text('Worker ${i + 1}'),
            subtitle: const Text('Active'),
            trailing: const Icon(Icons.chevron_right),
          ),
        ),
      ),
    );
  }
}
