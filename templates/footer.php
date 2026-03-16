<footer>
    <?php
    if (!isset($version)) {
        $version = function_exists('getVersion') ? getVersion() : '';
    }
    ?>
    <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    <p id="last-refreshed" style="margin-top: 10px;"></p> 
    <div class="version-number">
        Version: <?php echo htmlspecialchars((string)$version); ?>
    </div>   
</footer>
</body>
</html>
