import 'package:flutter/material.dart';
import '../services/supabase_service.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Future<void> _signOut() async {
    await supabaseService.signOut();
    Navigator.pushReplacementNamed(context, '/');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Dashboard'), actions: [
        IconButton(onPressed: _signOut, icon: const Icon(Icons.logout))
      ]),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ElevatedButton(
                onPressed: () => Navigator.pushNamed(context, '/request/new'),
                child: const Text('New Work Request')),
            const SizedBox(height: 8),
            ElevatedButton(
                onPressed: () => Navigator.pushNamed(context, '/requests'),
                child: const Text('View Work Requests')),
            const SizedBox(height: 8),
            ElevatedButton(
                onPressed: () => Navigator.pushNamed(context, '/info'),
                child: const Text('Information')),
          ],
        ),
      ),
    );
  }
}
