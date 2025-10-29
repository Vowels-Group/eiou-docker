import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../core/app/app_theme.dart';
import '../providers/container_provider.dart';
import '../widgets/container_card.dart';
import '../widgets/container_terminal.dart';

/// Main containers management screen
/// Replaces PHP walletIndex.html container section
class ContainersScreen extends StatefulWidget {
  const ContainersScreen({super.key});

  @override
  State<ContainersScreen> createState() => _ContainersScreenState();
}

class _ContainersScreenState extends State<ContainersScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final _searchController = TextEditingController();
  String _searchQuery = '';

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: NestedScrollView(
        headerSliverBuilder: (context, innerBoxIsScrolled) => [
          SliverAppBar(
            floating: true,
            snap: true,
            title: const Text('Docker Containers'),
            actions: [
              IconButton(
                icon: const Icon(Icons.refresh),
                onPressed: () {
                  context.read<ContainerProvider>().refreshContainers();
                },
              ),
              IconButton(
                icon: const Icon(Icons.add),
                onPressed: () => _showCreateContainerDialog(context),
              ),
            ],
            bottom: PreferredSize(
              preferredSize: const Size.fromHeight(110),
              child: Column(
                children: [
                  // Search Bar
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: TextField(
                      controller: _searchController,
                      decoration: InputDecoration(
                        hintText: 'Search containers...',
                        prefixIcon: const Icon(Icons.search),
                        suffixIcon: _searchQuery.isNotEmpty
                          ? IconButton(
                              icon: const Icon(Icons.clear),
                              onPressed: () {
                                setState(() {
                                  _searchController.clear();
                                  _searchQuery = '';
                                });
                              },
                            )
                          : null,
                        filled: true,
                        fillColor: Theme.of(context).colorScheme.surface,
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: BorderSide.none,
                        ),
                      ),
                      onChanged: (value) {
                        setState(() {
                          _searchQuery = value;
                        });
                      },
                    ),
                  ),
                  // Tab Bar
                  TabBar(
                    controller: _tabController,
                    tabs: [
                      Tab(
                        child: Consumer<ContainerProvider>(
                          builder: (context, provider, _) => Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const Text('All'),
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: Theme.of(context).colorScheme.primary.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Text(
                                  '${provider.containers.length}',
                                  style: TextStyle(
                                    fontSize: 11,
                                    color: Theme.of(context).colorScheme.primary,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      Tab(
                        child: Consumer<ContainerProvider>(
                          builder: (context, provider, _) => Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const Text('Running'),
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: AppTheme.containerRunning.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Text(
                                  '${provider.runningContainers.length}',
                                  style: TextStyle(
                                    fontSize: 11,
                                    color: AppTheme.containerRunning,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      Tab(
                        child: Consumer<ContainerProvider>(
                          builder: (context, provider, _) => Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              const Text('Stopped'),
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: AppTheme.containerStopped.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(10),
                                ),
                                child: Text(
                                  '${provider.stoppedContainers.length}',
                                  style: TextStyle(
                                    fontSize: 11,
                                    color: AppTheme.containerStopped,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
        body: TabBarView(
          controller: _tabController,
          children: [
            // All containers
            _ContainersList(
              filter: (containers) => _searchQuery.isEmpty
                ? containers
                : containers.where((c) =>
                    c.name.toLowerCase().contains(_searchQuery.toLowerCase()) ||
                    c.image.toLowerCase().contains(_searchQuery.toLowerCase())
                  ).toList(),
            ),
            // Running containers
            _ContainersList(
              filter: (containers) {
                final running = containers.where((c) => c.status == 'running').toList();
                return _searchQuery.isEmpty
                  ? running
                  : running.where((c) =>
                      c.name.toLowerCase().contains(_searchQuery.toLowerCase()) ||
                      c.image.toLowerCase().contains(_searchQuery.toLowerCase())
                    ).toList();
              },
            ),
            // Stopped containers
            _ContainersList(
              filter: (containers) {
                final stopped = containers.where((c) => c.status != 'running').toList();
                return _searchQuery.isEmpty
                  ? stopped
                  : stopped.where((c) =>
                      c.name.toLowerCase().contains(_searchQuery.toLowerCase()) ||
                      c.image.toLowerCase().contains(_searchQuery.toLowerCase())
                    ).toList();
              },
            ),
          ],
        ),
      ),
    );
  }

  void _showCreateContainerDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (context) => const CreateContainerDialog(),
    );
  }
}

// Containers list widget
class _ContainersList extends StatelessWidget {
  final List<DockerContainer> Function(List<DockerContainer>) filter;

  const _ContainersList({required this.filter});

  @override
  Widget build(BuildContext context) {
    return Consumer<ContainerProvider>(
      builder: (context, provider, _) {
        if (provider.isLoading && provider.containers.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }

        if (provider.error != null) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.error_outline,
                  size: 64,
                  color: Theme.of(context).colorScheme.error.withOpacity(0.5),
                ),
                const SizedBox(height: 16),
                Text(
                  'Error loading containers',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                Text(
                  provider.error!,
                  style: Theme.of(context).textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                FilledButton.icon(
                  onPressed: () => provider.loadContainers(),
                  icon: const Icon(Icons.refresh),
                  label: const Text('Retry'),
                ),
              ],
            ),
          );
        }

        final containers = filter(provider.containers);

        if (containers.isEmpty) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.inbox,
                  size: 64,
                  color: Theme.of(context).colorScheme.primary.withOpacity(0.3),
                ),
                const SizedBox(height: 16),
                Text(
                  'No containers found',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                Text(
                  'Create a new container to get started',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: () => provider.refreshContainers(),
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: containers.length,
            itemBuilder: (context, index) {
              final container = containers[index];
              return Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: ContainerCard(
                  container: container,
                  onTap: () => _showContainerDetails(context, container),
                ),
              );
            },
          ),
        );
      },
    );
  }

  void _showContainerDetails(BuildContext context, DockerContainer container) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => ContainerDetailsSheet(container: container),
    );
  }
}

// Container details bottom sheet
class ContainerDetailsSheet extends StatefulWidget {
  final DockerContainer container;

  const ContainerDetailsSheet({
    super.key,
    required this.container,
  });

  @override
  State<ContainerDetailsSheet> createState() => _ContainerDetailsSheetState();
}

class _ContainerDetailsSheetState extends State<ContainerDetailsSheet> {
  bool _showTerminal = false;

  @override
  Widget build(BuildContext context) {
    final provider = context.read<ContainerProvider>();

    return Container(
      height: MediaQuery.of(context).size.height * 0.85,
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: Column(
        children: [
          // Handle
          Center(
            child: Container(
              margin: const EdgeInsets.only(top: 12),
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.withOpacity(0.3),
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          // Header
          Padding(
            padding: const EdgeInsets.all(20),
            child: Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    color: AppTheme.getContainerStatusColor(widget.container.status)
                      .withOpacity(0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(
                    Icons.dashboard_customize,
                    color: AppTheme.getContainerStatusColor(widget.container.status),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        widget.container.name,
                        style: Theme.of(context).textTheme.titleLarge,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: AppTheme.getContainerStatusColor(
                                widget.container.status
                              ).withOpacity(0.1),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: Text(
                              widget.container.status.toUpperCase(),
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: AppTheme.getContainerStatusColor(
                                  widget.container.status
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Text(
                            widget.container.image,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ],
            ),
          ),
          // Action buttons
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                if (widget.container.status == 'running') ...[
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        await provider.stopContainer(widget.container.id);
                        if (context.mounted) Navigator.of(context).pop();
                      },
                      icon: const Icon(Icons.stop),
                      label: const Text('Stop'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        await provider.restartContainer(widget.container.id);
                      },
                      icon: const Icon(Icons.restart_alt),
                      label: const Text('Restart'),
                    ),
                  ),
                ] else ...[
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () async {
                        await provider.startContainer(widget.container.id);
                        if (context.mounted) Navigator.of(context).pop();
                      },
                      icon: const Icon(Icons.play_arrow),
                      label: const Text('Start'),
                    ),
                  ),
                ],
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      setState(() => _showTerminal = !_showTerminal);
                    },
                    icon: Icon(_showTerminal ? Icons.description : Icons.terminal),
                    label: Text(_showTerminal ? 'Logs' : 'Terminal'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          // Content
          Expanded(
            child: _showTerminal
              ? ContainerTerminal(containerId: widget.container.id)
              : _ContainerInfo(container: widget.container),
          ),
        ],
      ),
    );
  }
}

// Container info widget
class _ContainerInfo extends StatelessWidget {
  final DockerContainer container;

  const _ContainerInfo({required this.container});

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _buildInfoSection(
            context,
            title: 'Container Details',
            items: [
              _InfoItem('ID', container.id.substring(0, 12)),
              _InfoItem('Image', container.image),
              _InfoItem('Created', _formatDate(container.created)),
              _InfoItem('Status', container.status),
            ],
          ),
          const SizedBox(height: 24),
          _buildInfoSection(
            context,
            title: 'Network',
            items: container.ports.map((port) =>
              _InfoItem('Port', port)
            ).toList(),
          ),
          const SizedBox(height: 24),
          _buildInfoSection(
            context,
            title: 'Resources',
            items: [
              _InfoItem('CPU', '${container.stats?.cpuUsage ?? 0}%'),
              _InfoItem('Memory', _formatBytes(container.stats?.memoryUsage ?? 0)),
              _InfoItem('Disk', _formatBytes(container.stats?.diskUsage ?? 0)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildInfoSection(
    BuildContext context, {
    required String title,
    required List<_InfoItem> items,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 12),
        Card(
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: items.map((item) =>
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        item.label,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurface.withOpacity(0.6),
                        ),
                      ),
                      Text(
                        item.value,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    ],
                  ),
                ),
              ).toList(),
            ),
          ),
        ),
      ],
    );
  }

  String _formatDate(DateTime date) {
    final diff = DateTime.now().difference(date);
    if (diff.inDays > 0) return '${diff.inDays}d ago';
    if (diff.inHours > 0) return '${diff.inHours}h ago';
    if (diff.inMinutes > 0) return '${diff.inMinutes}m ago';
    return 'Just now';
  }

  String _formatBytes(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    if (bytes < 1024 * 1024 * 1024) {
      return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
    }
    return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(1)} GB';
  }
}

class _InfoItem {
  final String label;
  final String value;

  _InfoItem(this.label, this.value);
}

// Create container dialog (placeholder)
class CreateContainerDialog extends StatelessWidget {
  const CreateContainerDialog({super.key});

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Create Container'),
      content: const Text('Container creation UI will be implemented here'),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () {
            // TODO: Implement container creation
            Navigator.of(context).pop();
          },
          child: const Text('Create'),
        ),
      ],
    );
  }
}