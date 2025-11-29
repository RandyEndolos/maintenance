import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../services/supabase_service.dart';

class RequestFormScreen extends StatefulWidget {
  const RequestFormScreen({super.key});

  @override
  State<RequestFormScreen> createState() => _RequestFormScreenState();
}

class _RequestFormScreenState extends State<RequestFormScreen> {
  final _title = TextEditingController();
  final _desc = TextEditingController();
  bool _loading = false;
  File? _picked;

  Future<void> _pickImage() async {
    final p = ImagePicker();
    final f = await p.pickImage(source: ImageSource.gallery);
    if (f == null) return;
    setState(() => _picked = File(f.path));
  }

  Future<void> _submit() async {
    setState(() => _loading = true);
    try {
      String? fileUrl;
      if (_picked != null) {
        final path =
            'attachments/${DateTime.now().millisecondsSinceEpoch}_${_picked!.path.split('/').last}';
        fileUrl = await supabaseService.uploadFile(_picked!, 'public', path);
      }
      final payload = {
        'title': _title.text.trim(),
        'description': _desc.text.trim(),
        'attachment': fileUrl
      };
      await supabaseService.createWorkRequest(payload);
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Request created')));
      Navigator.pop(context);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Error: $e')));
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New Work Request')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(children: [
          TextField(
              controller: _title,
              decoration: const InputDecoration(labelText: 'Title')),
          const SizedBox(height: 8),
          TextField(
              controller: _desc,
              decoration: const InputDecoration(labelText: 'Description'),
              maxLines: 4),
          const SizedBox(height: 8),
          Row(children: [
            ElevatedButton(onPressed: _pickImage, child: const Text('Attach')),
            const SizedBox(width: 8),
            if (_picked != null) const Text('File selected')
          ]),
          const SizedBox(height: 16),
          ElevatedButton(
              onPressed: _loading ? null : _submit,
              child: _loading
                  ? const CircularProgressIndicator()
                  : const Text('Submit')),
        ]),
      ),
    );
  }
}
