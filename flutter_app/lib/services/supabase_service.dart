import 'dart:io';
import 'package:supabase_flutter/supabase_flutter.dart';

class SupabaseService {
  SupabaseClient get client => Supabase.instance.client;

  Future<AuthResponse> signIn(String email, String password) {
    return client.auth.signInWithPassword(email: email, password: password);
  }

  Future<AuthResponse> signUp(String email, String password) {
    return client.auth.signUp(email: email, password: password);
  }

  Future<void> signOut() async {
    await client.auth.signOut();
  }

  Future<List<Map<String, dynamic>>> getWorkRequests() async {
    try {
      final res = await client
          .from('work_requests')
          .select()
          .order('created_at', ascending: false)
          .execute();
      final data = res.data as List<dynamic>?;
      if (data == null) return [];
      return data.map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } catch (e) {
      rethrow;
    }
  }

  Future<Map<String, dynamic>> createWorkRequest(
      Map<String, dynamic> payload) async {
    try {
      final res =
          await client.from('work_requests').insert(payload).select().execute();
      final data = res.data as List<dynamic>?;
      if (data == null || data.isEmpty) throw Exception('Insert failed');
      return Map<String, dynamic>.from(data.first as Map);
    } catch (e) {
      rethrow;
    }
  }

  Future<String?> uploadFile(File file, String bucket, String path) async {
    try {
      final bytes = await file.readAsBytes();
      await client.storage.from(bucket).uploadBinary(path, bytes);
      final publicUrl = client.storage.from(bucket).getPublicUrl(path);
      return publicUrl.toString();
    } catch (e) {
      rethrow;
    }
  }
}

final supabaseService = SupabaseService();
