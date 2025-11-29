class WorkRequest {
  final int? id;
  final String title;
  final String description;
  final String? status;
  final String? createdAt;

  WorkRequest(
      {this.id,
      required this.title,
      required this.description,
      this.status,
      this.createdAt});

  factory WorkRequest.fromMap(Map<String, dynamic> m) => WorkRequest(
        id: m['id'] as int?,
        title: m['title'] as String? ?? '',
        description: m['description'] as String? ?? '',
        status: m['status'] as String?,
        createdAt: m['created_at']?.toString(),
      );

  Map<String, dynamic> toMap() => {
        if (id != null) 'id': id,
        'title': title,
        'description': description,
        'status': status ?? 'open',
      };
}
