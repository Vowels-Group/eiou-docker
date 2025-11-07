<?php
# Copyright 2025

/**
 * Dead Letter Queue Admin Interface
 *
 * Provides web interface for managing failed messages in the DLQ.
 * Features:
 * - View failed messages with filtering
 * - Retry individual or bulk messages
 * - Analyze failure patterns
 * - Archive old messages
 * - View statistics
 */

// Initialize dependencies
require_once '/etc/eiou/src/services/ServiceContainer.php';

// Get service container
$container = ServiceContainer::getInstance();
$dlqService = $container->getDeadLetterQueueService();

// Handle actions
$action = $_GET['action'] ?? 'list';
$message = null;
$error = null;

try {
    switch ($action) {
        case 'retry':
            $dlqId = (int)($_GET['id'] ?? 0);
            if ($dlqId > 0) {
                $result = $dlqService->retryMessage($dlqId);
                $message = $result['success'] ? 'Message retry successful' : 'Retry failed: ' . $result['message'];
            }
            break;

        case 'archive':
            $dlqId = (int)($_GET['id'] ?? 0);
            if ($dlqId > 0) {
                $dlqService->archiveMessage($dlqId);
                $message = 'Message archived successfully';
            }
            break;

        case 'bulk_retry':
            $failureReason = $_GET['failure_reason'] ?? null;
            $messageType = $_GET['message_type'] ?? null;
            $limit = (int)($_GET['limit'] ?? 10);
            $result = $dlqService->bulkRetry($failureReason, $messageType, $limit);
            $message = sprintf(
                'Bulk retry completed: %d successful, %d failed out of %d total',
                $result['successful'],
                $result['failed'],
                $result['total']
            );
            break;

        case 'cleanup':
            $days = (int)($_GET['days'] ?? 7);
            $count = $dlqService->cleanup($days);
            $message = sprintf('Cleanup completed: %d old messages deleted', $count);
            break;

        case 'bulk_archive':
            $days = (int)($_GET['days'] ?? 30);
            $count = $dlqService->bulkArchive($days);
            $message = sprintf('Bulk archive completed: %d messages archived', $count);
            break;
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Get current view parameters
$status = $_GET['status'] ?? 'failed';
$messageType = $_GET['type'] ?? null;
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;

// Fetch messages
$data = $dlqService->getMessages($status, $messageType, $page, $perPage);
$messages = $data['messages'];
$pagination = $data['pagination'];

// Get statistics
$stats = $dlqService->getStatistics();
$analysis = $dlqService->analyzeFailurePatterns();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dead Letter Queue - Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            color: #7f8c8d;
            font-size: 14px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-card.critical .stat-value {
            color: #e74c3c;
        }

        .stat-card.warning .stat-value {
            color: #f39c12;
        }

        .stat-card.success .stat-value {
            color: #27ae60;
        }

        .controls {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .controls-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #229954;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        select, input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        thead {
            background: #34495e;
            color: white;
        }

        th, td {
            padding: 12px;
            text-align: left;
        }

        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }

        tbody tr:hover {
            background: #e9ecef;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-failed {
            background: #e74c3c;
            color: white;
        }

        .badge-retrying {
            background: #f39c12;
            color: white;
        }

        .badge-resolved {
            background: #27ae60;
            color: white;
        }

        .badge-archived {
            background: #95a5a6;
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
        }

        .pagination a {
            padding: 8px 12px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }

        .pagination a:hover {
            background: #2980b9;
        }

        .pagination .current {
            padding: 8px 12px;
            background: #2c3e50;
            color: white;
            border-radius: 4px;
        }

        .message-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 12px;
        }

        .recommendations {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .recommendation-item {
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #f39c12;
            background: #fff3cd;
        }

        .recommendation-item.high {
            border-left-color: #e74c3c;
            background: #f8d7da;
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .timestamp {
            color: #7f8c8d;
            font-size: 12px;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
        }

        .nav-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: border-color 0.2s;
        }

        .nav-tab:hover {
            border-bottom-color: #3498db;
        }

        .nav-tab.active {
            border-bottom-color: #2c3e50;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Dead Letter Queue Management</h1>
        <p class="subtitle">Monitor and manage messages that failed all retry attempts</p>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($analysis['alert']) && $analysis['alert']['triggered']): ?>
            <div class="alert alert-warning">
                <strong>Alert:</strong> <?php echo htmlspecialchars($analysis['alert']['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card critical">
                <h3>Failed Messages</h3>
                <div class="stat-value"><?php echo $analysis['summary']['total_failed']; ?></div>
            </div>
            <div class="stat-card success">
                <h3>Resolved</h3>
                <div class="stat-value"><?php echo $analysis['summary']['total_resolved']; ?></div>
            </div>
            <div class="stat-card warning">
                <h3>Retrying</h3>
                <div class="stat-value"><?php echo $analysis['summary']['total_retrying']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Cleanup Eligible</h3>
                <div class="stat-value"><?php echo $analysis['summary']['cleanup_eligible']; ?></div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls">
            <div class="controls-row">
                <select onchange="window.location.href='?status=' + this.value">
                    <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    <option value="retrying" <?php echo $status === 'retrying' ? 'selected' : ''; ?>>Retrying</option>
                    <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>

                <a href="?action=cleanup&days=7" class="btn btn-warning" onclick="return confirm('Delete messages older than 7 days?')">
                    Cleanup (7 days)
                </a>

                <a href="?action=bulk_archive&days=30" class="btn btn-secondary" onclick="return confirm('Archive failed messages older than 30 days?')">
                    Bulk Archive
                </a>

                <a href="?" class="btn btn-primary">Refresh</a>
            </div>
        </div>

        <!-- Messages Table -->
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Sender</th>
                    <th>Failure Reason</th>
                    <th>Retries</th>
                    <th>Status</th>
                    <th>Failed At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            No messages found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($msg['id']); ?></td>
                            <td><?php echo htmlspecialchars($msg['message_type']); ?></td>
                            <td class="message-preview">
                                <?php echo htmlspecialchars($msg['sender_address'] ?? 'N/A'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($msg['failure_reason']); ?></td>
                            <td>
                                <?php echo $msg['retry_count']; ?>
                                <?php if ($msg['manual_retry_count'] > 0): ?>
                                    (<?php echo $msg['manual_retry_count']; ?> manual)
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($msg['status']); ?>">
                                    <?php echo strtoupper($msg['status']); ?>
                                </span>
                            </td>
                            <td class="timestamp">
                                <?php echo htmlspecialchars($msg['failed_at']); ?>
                            </td>
                            <td class="actions">
                                <?php if ($msg['status'] === 'failed'): ?>
                                    <a href="?action=retry&id=<?php echo $msg['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Retry this message?')">
                                        Retry
                                    </a>
                                    <a href="?action=archive&id=<?php echo $msg['id']; ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Archive this message?')">
                                        Archive
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?status=<?php echo $status; ?>&page=<?php echo $page - 1; ?>">Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?status=<?php echo $status; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $pagination['total_pages']): ?>
                    <a href="?status=<?php echo $status; ?>&page=<?php echo $page + 1; ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Recommendations -->
        <?php if (!empty($analysis['recommendations'])): ?>
            <div class="recommendations">
                <h2>Failure Pattern Analysis & Recommendations</h2>
                <?php foreach ($analysis['recommendations'] as $rec): ?>
                    <div class="recommendation-item <?php echo $rec['severity']; ?>">
                        <strong><?php echo htmlspecialchars($rec['failure_reason']); ?></strong>
                        (<?php echo $rec['count']; ?> occurrences)<br>
                        <small><?php echo htmlspecialchars($rec['recommendation']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
