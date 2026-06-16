<?php namespace ProcessWire;

/**
 * ProcessWire 2048 Game
 * 
 * Take a break from building sites with a classic 2048 game
 * 
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 */
class Process2048 extends Process {
    
    public static function getModuleInfo() {
        return [
            'title' => '2048',
            'summary' => 'Take a break with 2048 game in ProcessWire admin',
            'version' => '1.3.3',
            'author' => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon' => 'gamepad',
            'requires' => 'ProcessWire>=3.0.0',
            'autoload' => false,
            'singular' => true
        ];
    }
    
    public function init() {
        parent::init();
    }
    
    /**
     * Main game page
     */
    public function execute() {
        $this->headline('2048 Game');
        
        $this->addAssets();
        
        $user = $this->wire('user');
        $bestScore = $this->getBestScore($user);
        
        $out = $this->renderGame($bestScore);
        
        return $out;
    }
    
    /**
     * Debug: check installation status
     */
    public function executeDebug() {
        $out = "<h2>Debug Information</h2>";
        
        $out .= "<h3>Module Info</h3>";
        $out .= "<ul>";
        $out .= "<li>Module class: " . $this->className() . "</li>";
        $out .= "<li>Module installed: " . ($this->wire('modules')->isInstalled($this->className()) ? 'YES' : 'NO') . "</li>";
        $out .= "</ul>";
        
        $out .= "<h3>Admin Page Check</h3>";
        
        // Check for page with name 2048
        $page = $this->wire('pages')->get('name=2048, template=admin');
        $out .= "<ul>";
        $out .= "<li>Page exists: " . ($page->id ? 'YES (ID: ' . $page->id . ')' : 'NO') . "</li>";
        if($page->id) {
            $out .= "<li>Page path: " . $page->path . "</li>";
            $out .= "<li>Page title: " . $page->title . "</li>";
            $out .= "<li>Page template: " . $page->template->name . "</li>";
            $out .= "<li>Page parent: " . $page->parent->path . "</li>";
            $out .= "<li>Page process: " . $page->process . "</li>";
            $out .= "<li>Page status: " . $page->status . "</li>";
            $out .= "<li>Page is hidden: " . ($page->isHidden() ? 'YES' : 'NO') . "</li>";
        }
        $out .= "</ul>";
        
        $out .= "<h3>Database Table</h3>";
        $table = self::TABLE_NAME;
        $query = $this->wire('database')->query("SHOW TABLES LIKE '$table'");
        $exists = $query->rowCount() > 0;
        $out .= "<ul>";
        $out .= "<li>Table exists: " . ($exists ? 'YES' : 'NO') . "</li>";
        if($exists) {
            $countQuery = $this->wire('database')->query("SELECT COUNT(*) as cnt FROM `$table`");
            $count = $countQuery->fetch(\PDO::FETCH_ASSOC);
            $out .= "<li>Records count: " . $count['cnt'] . "</li>";
        }
        $out .= "</ul>";
        
        $out .= "<h3>Try to create page manually</h3>";
        $out .= "<p><a href='./create-page/' class='ui-button'>Create Admin Page</a></p>";
        
        return $out;
    }
    
    /**
     * Debug: manually create admin page
     */
    public function executeCreatePage() {
        $parent = $this->wire('pages')->get(2);
        
        $existingPage = $this->wire('pages')->get('name=2048, template=admin');
        if($existingPage->id) {
            $this->message('Page already exists at: ' . $existingPage->path);
            $this->wire('session')->redirect('../debug/');
            return;
        }
        
        try {
            $page = new \ProcessWire\Page();
            $page->template = 'admin';
            $page->parent = $parent;
            $page->name = '2048';
            $page->title = '2048';
            $page->process = $this;
            $page->save();
            
            $this->message('SUCCESS! Page created at: ' . $page->path);
            $this->message('Page ID: ' . $page->id);
            
        } catch(\Exception $e) {
            $this->error('ERROR: ' . $e->getMessage());
        }
        
        $this->wire('session')->redirect('../debug/');
    }
    
    /**
     * AJAX: Save score
     */
    public function executeSaveScore() {
        if(!$this->wire('config')->ajax) {
            throw new Wire404Exception();
        }
        
        $score = (int) $this->input->post('score');
        $gameTime = (int) $this->input->post('game_time');
        $user = $this->wire('user');
        
        if($score > 0) {
            $saved = $this->saveScore($user, $score, $gameTime);
            header('Content-Type: application/json');
            echo json_encode(['success' => $saved, 'score' => $score]);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false]);
        exit;
    }
    
    /**
     * Leaderboard page
     */
    public function executeLeaderboard() {
        $this->headline('Leaderboard - Top 10');
        $this->breadcrumbs->add(new Breadcrumb('../', '2048'));
        
        // Try to get from cache first (5 minutes cache)
        $cache = $this->wire('cache');
        $cacheKey = 'process2048_leaderboard';
        $scores = $cache->get($cacheKey);
        
        if($scores === null) {
            // Cache miss - get from database
            $scores = $this->getTopScores(10);
            // Cache for 5 minutes
            $cache->save($cacheKey, $scores, 300);
        }
        
        $out = $this->renderLeaderboard($scores);
        
        return $out;
    }
    
    /**
     * Add CSS and JS assets
     */
    protected function addAssets() {
        $config = $this->wire('config');
        $info = self::getModuleInfo();
        $version = $info['version'];
        
        $config->styles->add($config->urls->$this . 'game.css?v=' . $version);
        $config->scripts->add($config->urls->$this . 'game.js?v=' . $version);
    }
    
    /**
     * Render game interface
     */
    protected function renderGame($bestScore) {
        $user = $this->wire('user');
        
        $out = '';
        
        // Statistics cards
        $out .= <<<HTML
<div class="game2048-stats-grid">
    <div class="game2048-stat-card">
        <div class="game2048-stat-label">Current Score</div>
        <div class="game2048-stat-value" id="current-score">0</div>
        <div class="game2048-stat-desc">This game</div>
    </div>
    
    <div class="game2048-stat-card">
        <div class="game2048-stat-label">Best Score</div>
        <div class="game2048-stat-value" style="color: #3b82f6;" id="best-score">$bestScore</div>
        <div class="game2048-stat-desc">Personal record</div>
    </div>
</div>
HTML;
        
        // Instructions
        $out .= <<<HTML
<div class="game2048-info">
    <strong>How to play:</strong> Use <strong>arrow keys</strong> or <strong>swipe</strong> to move tiles. 
    When two tiles with the same number touch, they merge into one!
</div>
HTML;
        
        // Buttons
        $out .= <<<HTML
<div class="game2048-buttons">
    <button id="new-game-btn" class="ui-button ui-priority-secondary"><i class="fa fa-refresh"></i> New Game</button>
    <a href="./leaderboard/" class="ui-button"><i class="fa fa-trophy"></i> Leaderboard</a>
    <button id="sound-toggle" class="ui-button ui-button-sound" title="Sound: ON"><i class="fa fa-volume-up"></i></button>
</div>
HTML;
        
        // Game container
        $out .= <<<HTML
<div class="game-container">
    <div class="game-message" id="game-message" style="display:none;">
        <p></p>
        <button class="ui-button" id="retry-button">Try again</button>
    </div>
    <div class="grid-container" id="grid-container"></div>
    <div class="tile-container" id="tile-container"></div>
</div>
HTML;
        
        // Pass save URL to JS
        $saveUrl = $this->wire('page')->url . 'save-score/';
        $out .= "<script>var saveScoreUrl = '$saveUrl';</script>";
        
        // Add styles
        $out .= $this->getStyles();
        
        return $out;
    }
    
    /**
     * Render leaderboard
     */
    protected function renderLeaderboard($scores) {
        $out = '';
        
        // Calculate stats
        $totalGames = count($this->wire('database')->query("SELECT id FROM " . self::TABLE_NAME)->fetchAll());
        $topScore = !empty($scores) ? $scores[0]['score'] : 0;
        $avgTime = 0;
        
        if(!empty($scores)) {
            $times = array_column($scores, 'game_time');
            $avgTime = array_sum($times) / count($times);
        }
        
        // Round to avoid float modulo issues
        $avgTimeRounded = (int)round($avgTime);
        $avgMinutes = (int)floor($avgTimeRounded / 60);
        $avgSeconds = $avgTimeRounded % 60;
        $avgTimeDisplay = $avgMinutes > 0 
            ? sprintf('%dm %ds', $avgMinutes, $avgSeconds)
            : sprintf('%ds', $avgSeconds);
        
        // Statistics cards
        $out .= <<<HTML
<div class="game2048-stats-grid">
    <div class="game2048-stat-card">
        <div class="game2048-stat-label">Total Games</div>
        <div class="game2048-stat-value">{$totalGames}</div>
        <div class="game2048-stat-desc">All time</div>
    </div>
    
    <div class="game2048-stat-card">
        <div class="game2048-stat-label">Top Score</div>
        <div class="game2048-stat-value" style="color: #22c55e;">{$topScore}</div>
        <div class="game2048-stat-desc">Best result</div>
    </div>
    
    <div class="game2048-stat-card">
        <div class="game2048-stat-label">Average Time</div>
        <div class="game2048-stat-value" style="color: #3b82f6;">{$avgTimeDisplay}</div>
        <div class="game2048-stat-desc">Per game</div>
    </div>
</div>
HTML;
        
        // Buttons
        $out .= <<<HTML
<div class="game2048-buttons">
    <a href="../" class="ui-button"><i class="fa fa-gamepad"></i> Back to Game</a>
    <button onclick="location.reload()" class="ui-button ui-priority-secondary"><i class="fa fa-refresh"></i> Refresh</button>
</div>
HTML;
        
        // Table
        if(empty($scores)) {
            $out .= <<<HTML
<div class="game2048-info">
    No scores yet. Be the first to play!
</div>
HTML;
        } else {
            $out .= '<table class="AdminDataTable AdminDataList">';
            $out .= '<thead><tr>';
            $out .= '<th style="width: 80px;">Rank</th>';
            $out .= '<th>Player</th>';
            $out .= '<th style="width: 120px;">Score</th>';
            $out .= '<th style="width: 120px;">Game time</th>';
            $out .= '</tr></thead>';
            $out .= '<tbody>';
            
            $rank = 1;
            foreach($scores as $score) {
                $medal = '';
                if($rank === 1) $medal = '🥇 ';
                else if($rank === 2) $medal = '🥈 ';
                else if($rank === 3) $medal = '🥉 ';
                
                $gameTime = (int)$score['game_time'];
                $minutes = (int)floor($gameTime / 60);
                $seconds = $gameTime % 60;
                $timeDisplay = $minutes > 0 
                    ? sprintf('%dm %ds', $minutes, $seconds)
                    : sprintf('%ds', $seconds);
                
                $out .= '<tr>';
                $out .= '<td>' . $medal . '<strong>#' . $rank . '</strong></td>';
                $out .= '<td>' . $this->wire('sanitizer')->entities($score['username']) . '</td>';
                $out .= '<td><strong style="color: #3b82f6;">' . number_format($score['score']) . '</strong></td>';
                $out .= '<td>' . $timeDisplay . '</td>';
                $out .= '</tr>';
                $rank++;
            }
            
            $out .= '</tbody></table>';
        }
        
        // Add styles
        $out .= $this->getStyles();
        
        return $out;
    }
    
    /**
     * Get user's best score
     */
    protected function getBestScore($user) {
        $table = self::TABLE_NAME;
        $userId = (int) $user->id;
        
        $query = $this->wire('database')->prepare(
            "SELECT MAX(score) as best FROM `$table` WHERE user_id = :user_id"
        );
        $query->execute([':user_id' => $userId]);
        $result = $query->fetch(\PDO::FETCH_ASSOC);
        
        return $result['best'] ?? 0;
    }
    
    /**
     * Save user score
     */
    protected function saveScore($user, $score, $gameTime = 0) {
        $table = self::TABLE_NAME;
        
        $query = $this->wire('database')->prepare(
            "INSERT INTO `$table` (user_id, username, score, game_time, created) 
             VALUES (:user_id, :username, :score, :game_time, :created)"
        );
        
        $result = $query->execute([
            ':user_id' => $user->id,
            ':username' => $user->name,
            ':score' => $score,
            ':game_time' => $gameTime,
            ':created' => time()
        ]);
        
        // Clear leaderboard cache when new score is saved
        if($result) {
            $this->wire('cache')->delete('process2048_leaderboard');
        }
        
        return $result;
    }
    
    /**
     * Get top scores
     */
    protected function getTopScores($limit = 10) {
        $table = self::TABLE_NAME;
        $limit = (int) $limit;
        
        $query = $this->wire('database')->prepare(
            "SELECT user_id, username, score, game_time, created 
             FROM `$table` 
             ORDER BY score DESC, created ASC 
             LIMIT $limit"
        );
        $query->execute();
        
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get inline styles
     */
    protected function getStyles() {
        return <<<'HTML'
<style>
.game2048-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin: 20px 0 30px 0;
}

.game2048-stat-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.game2048-stat-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    margin-bottom: 8px;
}

.game2048-stat-value {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 4px;
    color: #111827;
}

.game2048-stat-desc {
    font-size: 12px;
    color: #9ca3af;
}

.game2048-info {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px 20px;
    margin: 20px 0;
    color: #374151;
}

.game2048-buttons {
    margin: 20px 0;
}

.game2048-buttons .ui-button {
    margin-right: 10px;
}

.ui-button-sound {
    min-width: 44px;
    padding-left: 10px;
    padding-right: 10px;
}

.ui-button-sound i {
    margin: 0;
}

.ui-button-sound:hover {
    background: #2563eb;
}

/* Table styling */
table.AdminDataTable {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    background: #fff;
}

table.AdminDataTable thead th {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
}

table.AdminDataTable tbody td {
    border: 1px solid #e5e7eb;
    padding: 12px;
    color: #1f2937;
}

table.AdminDataTable tbody tr:hover {
    background: #f9fafb;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .game2048-stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .game2048-stat-card {
        padding: 15px;
    }
    
    .game2048-stat-value {
        font-size: 28px;
    }
    
    .game2048-buttons .ui-button {
        display: block;
        width: 100%;
        margin-bottom: 10px;
        margin-right: 0;
    }
    
    table.AdminDataTable {
        font-size: 14px;
    }
    
    table.AdminDataTable thead th,
    table.AdminDataTable tbody td {
        padding: 8px;
    }
}
</style>
HTML;
    }
    
    const TABLE_NAME = 'game_break_scores';
    
    /**
     * Install: create database table and admin page
     */
    public function ___install() {
        $this->message('=== Starting installation ===');
        
        // Create database table
        $table = self::TABLE_NAME;
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(10) unsigned NOT NULL,
            `username` varchar(128) NOT NULL,
            `score` int(10) unsigned NOT NULL DEFAULT 0,
            `game_time` int(10) unsigned NOT NULL DEFAULT 0,
            `created` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `score` (`score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->wire('database')->exec($sql);
        $this->message('✓ Created table: ' . $table);
        
        // Create admin page
        $this->message('--- Creating admin page ---');
        
        // Try to find admin parent page
        $parent = $this->wire('pages')->get(2); // Admin root
        $this->message('Parent page found: ' . $parent->path . ' (ID: ' . $parent->id . ')');
        
        // Check if page already exists
        $existingPage = $this->wire('pages')->get('name=2048, has_parent=' . $parent->id);
        if($existingPage->id) {
            $this->message('! Page already exists: ' . $existingPage->path);
            return;
        }
        
        try {
            $page = new \ProcessWire\Page();
            $this->message('✓ Created new Page object');
            
            $page->template = 'admin';
            $this->message('✓ Set template: admin');
            
            $page->parent = $parent;
            $this->message('✓ Set parent: ' . $parent->path);
            
            $page->name = '2048';
            $this->message('✓ Set name: 2048');
            
            $page->title = '2048';
            $this->message('✓ Set title: 2048');
            
            $page->process = $this;
            $this->message('✓ Set process: ' . $this->className());
            
            $page->save();
            $this->message('✓✓✓ PAGE SAVED! Path: ' . $page->path . ' (ID: ' . $page->id . ')');
            
            // Check if page is actually in the database
            $checkPage = $this->wire('pages')->get($page->id);
            if($checkPage->id) {
                $this->message('✓ Verification: Page exists in DB');
            } else {
                $this->error('✗ Verification failed: Page not found after save!');
            }
            
        } catch(\Exception $e) {
            $this->error('✗ ERROR creating page: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
        
        $this->message('=== Installation complete ===');
    }
    
    /**
     * Uninstall: remove database table and admin page
     */
    public function ___uninstall() {
        // Remove database table
        $table = self::TABLE_NAME;
        $this->wire('database')->exec("DROP TABLE IF EXISTS `$table`");
        $this->message('Removed table: ' . $table);
        
        // Remove admin page
        $page = $this->wire('pages')->get('name=2048, template=admin');
        if($page->id) {
            $this->wire('pages')->delete($page, true);
            $this->message('Removed admin page: ' . $page->path);
        }
    }
}
