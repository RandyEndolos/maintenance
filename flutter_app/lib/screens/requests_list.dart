import 'package:flutter/material.dart';
import '../services/supabase_service.dart';
import '../models/work_request.dart';

class RequestsListScreen extends StatefulWidget {
  const RequestsListScreen({super.key});

  @override
  State<RequestsListScreen> createState() => _RequestsListScreenState();
}

class _RequestsListScreenState extends State<RequestsListScreen> {
  bool _loading = false;
  List<WorkRequest> _items = [];

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final rows = await supabaseService.getWorkRequests();
      setState(() => _items = rows.map((r) => WorkRequest.fromMap(r)).toList());
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Error: $e')));
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Work Requests')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView.builder(
              itemCount: _items.length,
              itemBuilder: (context, i) {
                final it = _items[i];
                return ListTile(
                  title: Text(it.title),
                  subtitle: Text(it.description),
                  trailing: Text(it.status ?? ''),
                );
              },
            ),
    );
  }
}
