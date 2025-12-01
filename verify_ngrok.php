<?php
/**
 * verify_ngrok.php — Quick verification tool for ngrok ML API connection
 * Run this after setting up ngrok to ensure everything works
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ml_client.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ngrok ML API Verification</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #667eea;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: 600;
            color: #555;
        }
        .value {
            font-family: 'Courier New', monospace;
            color: #333;
            word-break: break-all;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
        }
        .endpoint-test {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .endpoint-name {
            font-weight: 600;
            color: #667eea;
            margin-bottom: 8px;
        }
        .response {
            background: #f1f3f5;
            padding: 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 200px;
            overflow: auto;
            white-space: pre-wrap;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5568d3;
        }
        .checklist {
            list-style: none;
            padding: 0;
        }
        .checklist li {
            padding: 8px 0;
            padding-left: 30px;
            position: relative;
        }
        .checklist li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
            font-size: 18px;
        }
        .checklist li.fail:before {
            content: '✗';
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 ngrok ML API Verification</h1>
            <p>Testing connection to your Machine Learning API</p>
        </div>

        <div class="content">
            <?php
            // Configuration info
            $is_ngrok = strpos(ML_BASE, 'ngrok') !== false;
            $is_cloudflare = strpos(ML_BASE, 'trycloudflare') !== false;
            ?>

            <!-- Configuration Section -->
            <div class="section">
                <h2>📋 Current Configuration</h2>
                <div class="info-row">
                    <span class="label">ML Base URL:</span>
                    <span class="value"><?php echo htmlspecialchars(ML_BASE); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">API Key Length:</span>
                    <span class="value"><?php echo strlen(ML_KEY); ?> characters</span>
                </div>
                <div class="info-row">
                    <span class="label">Tunnel Type:</span>
                    <span class="value">
                        <?php
                        if ($is_ngrok) {
                            echo '<span class="status success">ngrok (Permanent ✓)</span>';
                        } elseif ($is_cloudflare) {
                            echo '<span class="status warning">Cloudflare (Temporary ⚠️)</span>';
                        } else {
                            echo '<span class="status">Unknown</span>';
                        }
                        ?>
                    </span>
                </div>
            </div>

            <!-- Connection Tests -->
            <div class="section">
                <h2>🧪 Endpoint Tests</h2>

                <?php
                $tests = [
                    'Version Check' => ml_version(),
                    'Health Check' => ml_health(),
                ];

                $all_passed = true;
                foreach ($tests as $name => $result) {
                    $has_error = isset($result['error']);
                    if ($has_error) $all_passed = false;
                    ?>
                    <div class="endpoint-test">
                        <div class="endpoint-name">
                            <?php echo $name; ?>
                            <?php if (!$has_error): ?>
                                <span class="status success">✓ Success</span>
                            <?php else: ?>
                                <span class="status error">✗ Failed</span>
                            <?php endif; ?>
                        </div>
                        <div class="response"><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)); ?></div>
                    </div>
                    <?php
                }
                ?>
            </div>

            <!-- Status Checklist -->
            <div class="section">
                <h2>✅ Setup Checklist</h2>
                <ul class="checklist">
                    <li class="<?php echo $is_ngrok ? '' : 'fail'; ?>">
                        Using ngrok static domain (not temporary Cloudflare)
                    </li>
                    <li class="<?php echo !isset($tests['Version Check']['error']) ? '' : 'fail'; ?>">
                        /version endpoint responding
                    </li>
                    <li class="<?php echo !isset($tests['Health Check']['error']) ? '' : 'fail'; ?>">
                        /health endpoint responding
                    </li>
                    <li class="<?php echo strlen(ML_KEY) > 20 ? '' : 'fail'; ?>">
                        API key configured (<?php echo strlen(ML_KEY); ?> chars)
                    </li>
                    <li class="<?php echo $all_passed ? '' : 'fail'; ?>">
                        All endpoints accessible
                    </li>
                </ul>
            </div>

            <!-- Recommendations -->
            <?php if ($is_cloudflare): ?>
            <div class="section">
                <h2>⚠️ Recommendation</h2>
                <p style="margin-bottom: 15px;">
                    You're still using a temporary Cloudflare tunnel. This URL will expire and require updates.
                </p>
                <p>
                    <strong>Next step:</strong> Follow <a href="NGROK_SETUP.md" style="color: #667eea;">NGROK_SETUP.md</a>
                    to set up a permanent ngrok domain.
                </p>
            </div>
            <?php elseif ($all_passed && $is_ngrok): ?>
            <div class="section" style="border-left-color: #28a745; background: #d4edda;">
                <h2 style="color: #28a745;">🎉 Perfect Setup!</h2>
                <p style="color: #155724;">
                    Your ngrok tunnel is working perfectly. Your ML API URL is now permanent and won't need updates!
                </p>
            </div>
            <?php elseif (!$all_passed): ?>
            <div class="section" style="border-left-color: #dc3545; background: #f8d7da;">
                <h2 style="color: #dc3545;">❌ Connection Issues</h2>
                <p style="color: #721c24; margin-bottom: 10px;">
                    <strong>Troubleshooting steps:</strong>
                </p>
                <ol style="margin-left: 20px; color: #721c24;">
                    <li>Make sure your Colab notebook is running</li>
                    <li>Check that ngrok tunnel is active in Colab output</li>
                    <li>Verify ML_BASE and ML_KEY in includes/config.php match your Colab</li>
                    <li>Test the URL directly in browser: <?php echo htmlspecialchars(ML_BASE . '/version'); ?></li>
                </ol>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div style="text-align: center; margin-top: 30px;">
                <a href="ping_ml.php" class="btn">Full API Test (ping_ml.php)</a>
                <a href="javascript:location.reload()" class="btn" style="background: #6c757d;">Refresh Tests</a>
            </div>
        </div>
    </div>
</body>
</html>
