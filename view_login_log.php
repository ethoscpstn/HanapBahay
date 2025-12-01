<!DOCTYPE html>
<html>
<head>
    <title>Login Debug Log</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; }
        pre { background: #252526; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .info { color: #dcdcaa; }
        button { padding: 10px 20px; background: #0e639c; color: white; border: none; cursor: pointer; margin: 10px 5px; }
        button:hover { background: #1177bb; }
    </style>
</head>
<body>
    <h1>Login Debug Log</h1>
    <button onclick="location.reload()">Refresh Log</button>
    <button onclick="clearLog()">Clear Log</button>
    <button onclick="window.location='LoginModule.php'">Go to Login</button>
    <hr>

    <?php
    $logFile = __DIR__ . '/login-debug.log';
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        if (!empty($content)) {
            // Color-code different types of messages
            $content = htmlspecialchars($content);
            $content = preg_replace('/\b(SUCCESS|Redirecting)\b/', '<span class="success">$1</span>', $content);
            $content = preg_replace('/\b(FAILED|Error|not found)\b/i', '<span class="error">$1</span>', $content);
            $content = preg_replace('/\[([^\]]+)\]/', '<span class="info">[$1]</span>', $content);

            echo "<pre>$content</pre>";
        } else {
            echo "<p>Log file is empty. Try logging in first.</p>";
        }
    } else {
        echo "<p>No log file found yet. Try logging in first.</p>";
    }
    ?>

    <script>
    function clearLog() {
        if (confirm('Clear the log file?')) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'clear_log=1'
            }).then(() => location.reload());
        }
    }

    <?php
    if (isset($_POST['clear_log'])) {
        $logFile = __DIR__ . '/login-debug.log';
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
    }
    ?>
    </script>
</body>
</html>
