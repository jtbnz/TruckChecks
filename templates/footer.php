<footer>
    <? $version = $_SESSION['version']; ?>
    <p><a href="index.php" class="button touch-button">Return to Home</a></p>
    <p id="last-refreshed" style="margin-top: 10px;"></p> 
    <div class="version-number">
        Version: <?php echo htmlspecialchars($version); ?>
    </div>   
</footer>
</body>
</html>
