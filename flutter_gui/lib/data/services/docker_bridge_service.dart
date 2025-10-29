import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import '../../domain/models/container.dart';
import '../../domain/models/network.dart';
import '../../domain/models/image.dart';
import '../../core/errors/exceptions.dart';

/// Bridge service to connect Flutter GUI with existing PHP backend
/// Maintains compatibility with current eiou-docker API endpoints
class DockerBridgeService {
  final Dio _dio;
  final String baseUrl;
  WebSocketChannel? _wsChannel;

  DockerBridgeService({
    required this.baseUrl,
    Dio? dio,
  }) : _dio = dio ?? Dio() {
    _dio.options = BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
    );

    // Add interceptors for error handling
    _dio.interceptors.add(InterceptorsWrapper(
      onError: (error, handler) {
        if (error.response?.statusCode == 401) {
          // Handle authentication errors
          throw AuthenticationException('Session expired');
        }
        handler.next(error);
      },
    ));
  }

  /// Connect to WebSocket for real-time updates
  Future<void> connectWebSocket() async {
    try {
      final wsUrl = baseUrl.replaceAll('http', 'ws');
      _wsChannel = WebSocketChannel.connect(Uri.parse('$wsUrl/ws'));

      _wsChannel!.stream.listen(
        (message) => _handleWebSocketMessage(message),
        onError: (error) => print('WebSocket error: $error'),
        onDone: () => _reconnectWebSocket(),
      );
    } catch (e) {
      throw NetworkException('Failed to connect WebSocket: $e');
    }
  }

  void _handleWebSocketMessage(dynamic message) {
    try {
      final data = json.decode(message);
      // Handle real-time updates
      switch (data['type']) {
        case 'container_update':
          // Notify container provider
          break;
        case 'network_update':
          // Notify network provider
          break;
        case 'log_update':
          // Notify log provider
          break;
      }
    } catch (e) {
      print('Error handling WebSocket message: $e');
    }
  }

  Future<void> _reconnectWebSocket() async {
    await Future.delayed(const Duration(seconds: 5));
    await connectWebSocket();
  }

  /// Authenticate user (maps to existing PHP session)
  Future<Map<String, dynamic>> authenticate({
    required String walletAddress,
    required String signature,
  }) async {
    try {
      final response = await _dio.post('/api/auth', data: {
        'wallet_address': walletAddress,
        'signature': signature,
      });
      return response.data;
    } catch (e) {
      throw AuthenticationException('Authentication failed: $e');
    }
  }

  /// Get all containers
  Future<List<DockerContainer>> getContainers() async {
    try {
      final response = await _dio.get('/api/containers');
      return (response.data as List)
          .map((json) => DockerContainer.fromJson(json))
          .toList();
    } catch (e) {
      throw NetworkException('Failed to fetch containers: $e');
    }
  }

  /// Get container details
  Future<DockerContainer> getContainer(String containerId) async {
    try {
      final response = await _dio.get('/api/containers/$containerId');
      return DockerContainer.fromJson(response.data);
    } catch (e) {
      throw NetworkException('Failed to fetch container details: $e');
    }
  }

  /// Start container
  Future<void> startContainer(String containerId) async {
    try {
      await _dio.post('/api/containers/$containerId/start');
    } catch (e) {
      throw OperationException('Failed to start container: $e');
    }
  }

  /// Stop container
  Future<void> stopContainer(String containerId) async {
    try {
      await _dio.post('/api/containers/$containerId/stop');
    } catch (e) {
      throw OperationException('Failed to stop container: $e');
    }
  }

  /// Restart container
  Future<void> restartContainer(String containerId) async {
    try {
      await _dio.post('/api/containers/$containerId/restart');
    } catch (e) {
      throw OperationException('Failed to restart container: $e');
    }
  }

  /// Execute command in container
  Future<String> executeCommand({
    required String containerId,
    required String command,
    bool interactive = false,
  }) async {
    try {
      final response = await _dio.post('/api/containers/$containerId/exec', data: {
        'command': command,
        'interactive': interactive,
      });
      return response.data['output'];
    } catch (e) {
      throw OperationException('Failed to execute command: $e');
    }
  }

  /// Get container logs
  Stream<String> getContainerLogs(String containerId, {int tail = 100}) {
    return Stream.periodic(const Duration(seconds: 1)).asyncMap((_) async {
      try {
        final response = await _dio.get('/api/containers/$containerId/logs',
          queryParameters: {'tail': tail});
        return response.data['logs'];
      } catch (e) {
        throw NetworkException('Failed to fetch logs: $e');
      }
    });
  }

  /// Get all networks
  Future<List<DockerNetwork>> getNetworks() async {
    try {
      final response = await _dio.get('/api/networks');
      return (response.data as List)
          .map((json) => DockerNetwork.fromJson(json))
          .toList();
    } catch (e) {
      throw NetworkException('Failed to fetch networks: $e');
    }
  }

  /// Create network
  Future<void> createNetwork(Map<String, dynamic> config) async {
    try {
      await _dio.post('/api/networks', data: config);
    } catch (e) {
      throw OperationException('Failed to create network: $e');
    }
  }

  /// Get all images
  Future<List<DockerImage>> getImages() async {
    try {
      final response = await _dio.get('/api/images');
      return (response.data as List)
          .map((json) => DockerImage.fromJson(json))
          .toList();
    } catch (e) {
      throw NetworkException('Failed to fetch images: $e');
    }
  }

  /// Pull image
  Stream<String> pullImage(String imageName) {
    return Stream.periodic(const Duration(milliseconds: 500)).asyncMap((_) async {
      try {
        final response = await _dio.post('/api/images/pull', data: {
          'image': imageName,
        });
        return response.data['status'];
      } catch (e) {
        throw OperationException('Failed to pull image: $e');
      }
    });
  }

  /// Get EIOU wallet info
  Future<Map<String, dynamic>> getWalletInfo() async {
    try {
      final response = await _dio.get('/api/wallet');
      return response.data;
    } catch (e) {
      throw NetworkException('Failed to fetch wallet info: $e');
    }
  }

  /// Send EIOU transaction
  Future<Map<String, dynamic>> sendTransaction({
    required String recipient,
    required double amount,
    required String signature,
  }) async {
    try {
      final response = await _dio.post('/api/transactions', data: {
        'recipient': recipient,
        'amount': amount,
        'signature': signature,
      });
      return response.data;
    } catch (e) {
      throw OperationException('Failed to send transaction: $e');
    }
  }

  /// Get topology configuration
  Future<Map<String, dynamic>> getTopology() async {
    try {
      final response = await _dio.get('/api/topology');
      return response.data;
    } catch (e) {
      throw NetworkException('Failed to fetch topology: $e');
    }
  }

  /// Update topology configuration
  Future<void> updateTopology(Map<String, dynamic> config) async {
    try {
      await _dio.put('/api/topology', data: config);
    } catch (e) {
      throw OperationException('Failed to update topology: $e');
    }
  }

  /// Get system metrics
  Future<Map<String, dynamic>> getMetrics() async {
    try {
      final response = await _dio.get('/api/metrics');
      return response.data;
    } catch (e) {
      throw NetworkException('Failed to fetch metrics: $e');
    }
  }

  /// Clean up resources
  void dispose() {
    _wsChannel?.sink.close();
    _dio.close();
  }
}

/// Custom exceptions for Docker Bridge operations
class AuthenticationException implements Exception {
  final String message;
  AuthenticationException(this.message);
}

class NetworkException implements Exception {
  final String message;
  NetworkException(this.message);
}

class OperationException implements Exception {
  final String message;
  OperationException(this.message);
}