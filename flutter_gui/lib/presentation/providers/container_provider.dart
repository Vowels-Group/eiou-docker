import 'package:flutter/foundation.dart';
import '../../data/services/docker_bridge_service.dart';
import '../../domain/models/container.dart';

/// Provider for managing Docker container state
/// Implements patterns from eiou-wallet-flutter
class ContainerProvider extends ChangeNotifier {
  final DockerBridgeService _dockerService;

  List<DockerContainer> _containers = [];
  DockerContainer? _selectedContainer;
  bool _isLoading = false;
  String? _error;
  Map<String, Stream<String>> _logStreams = {};

  ContainerProvider({required DockerBridgeService dockerService})
      : _dockerService = dockerService {
    loadContainers();
  }

  // Getters
  List<DockerContainer> get containers => _containers;
  DockerContainer? get selectedContainer => _selectedContainer;
  bool get isLoading => _isLoading;
  String? get error => _error;

  List<DockerContainer> get runningContainers =>
      _containers.where((c) => c.status == 'running').toList();

  List<DockerContainer> get stoppedContainers =>
      _containers.where((c) => c.status != 'running').toList();

  /// Load all containers
  Future<void> loadContainers() async {
    _setLoading(true);
    _clearError();

    try {
      _containers = await _dockerService.getContainers();
      notifyListeners();
    } catch (e) {
      _setError('Failed to load containers: ${e.toString()}');
    } finally {
      _setLoading(false);
    }
  }

  /// Refresh containers
  Future<void> refreshContainers() async {
    await loadContainers();
  }

  /// Select a container for detailed view
  Future<void> selectContainer(String containerId) async {
    _setLoading(true);
    _clearError();

    try {
      _selectedContainer = await _dockerService.getContainer(containerId);
      notifyListeners();
    } catch (e) {
      _setError('Failed to load container details: ${e.toString()}');
    } finally {
      _setLoading(false);
    }
  }

  /// Start a container
  Future<bool> startContainer(String containerId) async {
    _setLoading(true);
    _clearError();

    try {
      await _dockerService.startContainer(containerId);
      await loadContainers();
      return true;
    } catch (e) {
      _setError('Failed to start container: ${e.toString()}');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Stop a container
  Future<bool> stopContainer(String containerId) async {
    _setLoading(true);
    _clearError();

    try {
      await _dockerService.stopContainer(containerId);
      await loadContainers();
      return true;
    } catch (e) {
      _setError('Failed to stop container: ${e.toString()}');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Restart a container
  Future<bool> restartContainer(String containerId) async {
    _setLoading(true);
    _clearError();

    try {
      await _dockerService.restartContainer(containerId);
      await loadContainers();
      return true;
    } catch (e) {
      _setError('Failed to restart container: ${e.toString()}');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Execute command in container
  Future<String?> executeCommand({
    required String containerId,
    required String command,
    bool interactive = false,
  }) async {
    _clearError();

    try {
      final output = await _dockerService.executeCommand(
        containerId: containerId,
        command: command,
        interactive: interactive,
      );
      return output;
    } catch (e) {
      _setError('Failed to execute command: ${e.toString()}');
      return null;
    }
  }

  /// Start streaming logs for a container
  Stream<String> getContainerLogs(String containerId, {int tail = 100}) {
    if (!_logStreams.containsKey(containerId)) {
      _logStreams[containerId] = _dockerService.getContainerLogs(
        containerId,
        tail: tail,
      );
    }
    return _logStreams[containerId]!;
  }

  /// Stop streaming logs for a container
  void stopLogStream(String containerId) {
    _logStreams.remove(containerId);
  }

  /// Filter containers by search query
  List<DockerContainer> searchContainers(String query) {
    if (query.isEmpty) return _containers;

    final lowerQuery = query.toLowerCase();
    return _containers.where((container) =>
      container.name.toLowerCase().contains(lowerQuery) ||
      container.image.toLowerCase().contains(lowerQuery) ||
      container.id.toLowerCase().contains(lowerQuery)
    ).toList();
  }

  /// Get container stats
  Map<String, int> getContainerStats() {
    return {
      'total': _containers.length,
      'running': runningContainers.length,
      'stopped': stoppedContainers.length,
      'paused': _containers.where((c) => c.status == 'paused').length,
    };
  }

  // Private helper methods
  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String message) {
    _error = message;
    notifyListeners();
  }

  void _clearError() {
    _error = null;
  }

  @override
  void dispose() {
    _logStreams.clear();
    super.dispose();
  }
}