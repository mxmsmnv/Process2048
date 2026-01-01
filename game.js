/**
 * 2048 Game Logic
 * Classic 2048 game implementation for ProcessWire
 */

class Game2048 {
    constructor() {
        this.size = 4;
        this.grid = [];
        this.score = 0;
        this.bestScore = parseInt(document.getElementById('best-score').textContent) || 0;
        this.gameOver = false;
        this.won = false;
        this.startTime = null;
        this.gameTime = 0;
        
        // Sound system
        this.soundEnabled = localStorage.getItem('game2048_sound') !== 'false';
        this.audioContext = null;
        this.initAudio();
        
        this.initGrid();
        this.setupControls();
        this.setupSoundToggle();
        this.startGame();
    }
    
    /**
     * Initialize Web Audio API
     */
    initAudio() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContext();
        } catch (e) {
            console.warn('Web Audio API not supported');
            this.soundEnabled = false;
        }
    }
    
    /**
     * Play sound effect
     */
    playSound(type) {
        if (!this.soundEnabled || !this.audioContext) return;
        
        const ctx = this.audioContext;
        const oscillator = ctx.createOscillator();
        const gainNode = ctx.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        
        // Different sounds for different actions
        switch(type) {
            case 'move':
                oscillator.frequency.value = 220;
                gainNode.gain.value = 0.1;
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.05);
                break;
                
            case 'merge':
                oscillator.frequency.value = 440;
                gainNode.gain.value = 0.15;
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.1);
                break;
                
            case 'spawn':
                oscillator.frequency.value = 330;
                gainNode.gain.value = 0.08;
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.08);
                break;
                
            case 'win':
                // Victory sound - ascending notes
                [523, 659, 784, 1047].forEach((freq, i) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = freq;
                    gain.gain.value = 0.2;
                    osc.start(ctx.currentTime + i * 0.1);
                    osc.stop(ctx.currentTime + i * 0.1 + 0.2);
                });
                break;
                
            case 'gameover':
                // Game over sound - descending notes
                [392, 349, 294, 262].forEach((freq, i) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = freq;
                    gain.gain.value = 0.15;
                    osc.start(ctx.currentTime + i * 0.1);
                    osc.stop(ctx.currentTime + i * 0.1 + 0.15);
                });
                break;
        }
    }
    
    /**
     * Setup sound toggle button
     */
    setupSoundToggle() {
        const toggleBtn = document.getElementById('sound-toggle');
        if (!toggleBtn) return;
        
        this.updateSoundButton();
        
        toggleBtn.addEventListener('click', () => {
            this.soundEnabled = !this.soundEnabled;
            localStorage.setItem('game2048_sound', this.soundEnabled);
            this.updateSoundButton();
            
            // Resume audio context if needed (browser autoplay policy)
            if (this.soundEnabled && this.audioContext && this.audioContext.state === 'suspended') {
                this.audioContext.resume();
            }
            
            // Play test sound
            if (this.soundEnabled) {
                this.playSound('spawn');
            }
        });
    }
    
    /**
     * Update sound button icon
     */
    updateSoundButton() {
        const toggleBtn = document.getElementById('sound-toggle');
        if (!toggleBtn) return;
        
        const icon = toggleBtn.querySelector('i');
        if (this.soundEnabled) {
            icon.className = 'fa fa-volume-up';
            toggleBtn.title = 'Sound: ON';
        } else {
            icon.className = 'fa fa-volume-off';
            toggleBtn.title = 'Sound: OFF';
        }
    }
    
    initGrid() {
        const container = document.getElementById('grid-container');
        container.innerHTML = '';
        
        // Set dynamic grid layout
        container.style.gridTemplateColumns = `repeat(${this.size}, 1fr)`;
        container.style.gridTemplateRows = `repeat(${this.size}, 1fr)`;
        
        for(let i = 0; i < this.size * this.size; i++) {
            const cell = document.createElement('div');
            cell.className = 'grid-cell';
            container.appendChild(cell);
        }
        
        this.grid = Array(this.size).fill(null).map(() => Array(this.size).fill(0));
        
        // Generate dynamic tile positions
        this.generateTilePositions();
    }
    
    /**
     * Generate dynamic tile position CSS
     */
    generateTilePositions() {
        // Remove old style if exists
        const oldStyle = document.getElementById('dynamic-tile-positions');
        if(oldStyle) {
            oldStyle.remove();
        }
        
        // Create new style element
        const style = document.createElement('style');
        style.id = 'dynamic-tile-positions';
        
        let css = '';
        
        // Desktop: gap = 10px
        const gap = 10;
        const totalGap = gap * (this.size - 1);
        
        for(let row = 0; row < this.size; row++) {
            for(let col = 0; col < this.size; col++) {
                // Correct formula: distribute available space equally
                css += `.tile-position-${row}-${col} { `;
                css += `top: calc(${row} * (100% - ${totalGap}px) / ${this.size} + ${row * gap}px); `;
                css += `left: calc(${col} * (100% - ${totalGap}px) / ${this.size} + ${col * gap}px); `;
                css += `width: calc((100% - ${totalGap}px) / ${this.size}); `;
                css += `height: calc((100% - ${totalGap}px) / ${this.size}); `;
                css += `}\n`;
            }
        }
        
        // Dynamic font sizing based on grid size
        const baseFontSize = Math.max(20, Math.min(40, 240 / this.size));
        const largeFontSize = baseFontSize * 0.85;
        const xlargeFontSize = baseFontSize * 0.7;
        
        css += `
.tile {
    font-size: ${baseFontSize}px;
}

.tile-128,
.tile-256,
.tile-512 {
    font-size: ${largeFontSize}px;
}

.tile-1024,
.tile-2048,
.tile-4096,
.tile-8192 {
    font-size: ${xlargeFontSize}px;
}
`;
        
        // Mobile: gap = 2vw
        css += `@media (max-width: 768px) {\n`;
        const mobileGap = 2;
        const totalMobileGap = mobileGap * (this.size - 1);
        
        for(let row = 0; row < this.size; row++) {
            for(let col = 0; col < this.size; col++) {
                css += `.tile-position-${row}-${col} { `;
                css += `top: calc(${row} * (100% - ${totalMobileGap}vw) / ${this.size} + ${row * mobileGap}vw); `;
                css += `left: calc(${col} * (100% - ${totalMobileGap}vw) / ${this.size} + ${col * mobileGap}vw); `;
                css += `width: calc((100% - ${totalMobileGap}vw) / ${this.size}); `;
                css += `height: calc((100% - ${totalMobileGap}vw) / ${this.size}); `;
                css += `}\n`;
            }
        }
        
        // Mobile font sizes (slightly smaller)
        const mobileFontSize = baseFontSize * 0.9;
        const mobileLargeFontSize = largeFontSize * 0.9;
        const mobileXLargeFontSize = xlargeFontSize * 0.9;
        
        css += `
.tile {
    font-size: ${mobileFontSize}px;
}

.tile-128,
.tile-256,
.tile-512 {
    font-size: ${mobileLargeFontSize}px;
}

.tile-1024,
.tile-2048,
.tile-4096,
.tile-8192 {
    font-size: ${mobileXLargeFontSize}px;
}
`;
        
        css += `}`;
        
        style.textContent = css;
        document.head.appendChild(style);
    }
    
    setupControls() {
        // Keyboard controls
        document.addEventListener('keydown', (e) => {
            if(this.gameOver && !this.won) return;
            
            const keyMap = {
                'ArrowUp': 'up',
                'ArrowDown': 'down',
                'ArrowLeft': 'left',
                'ArrowRight': 'right'
            };
            
            const direction = keyMap[e.key];
            if(direction) {
                e.preventDefault();
                this.move(direction);
            }
        });
        
        // Touch/Swipe controls for mobile and tablets
        this.setupTouchControls();
        
        // New game button
        document.getElementById('new-game-btn').addEventListener('click', () => {
            this.startGame();
        });
        
        // Retry button
        document.getElementById('retry-button').addEventListener('click', () => {
            this.startGame();
        });
    }
    
    setupTouchControls() {
        const gameContainer = document.getElementById('grid-container');
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        
        const minSwipeDistance = 30; // Minimum distance in pixels to register as swipe
        
        gameContainer.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
        }, { passive: true });
        
        gameContainer.addEventListener('touchend', (e) => {
            if(this.gameOver && !this.won) return;
            
            touchEndX = e.changedTouches[0].screenX;
            touchEndY = e.changedTouches[0].screenY;
            
            this.handleSwipe(touchStartX, touchStartY, touchEndX, touchEndY, minSwipeDistance);
        }, { passive: true });
    }
    
    handleSwipe(startX, startY, endX, endY, minDistance) {
        const deltaX = endX - startX;
        const deltaY = endY - startY;
        
        // Check if swipe distance is significant enough
        if(Math.abs(deltaX) < minDistance && Math.abs(deltaY) < minDistance) {
            return; // Too short to be a swipe
        }
        
        // Determine if swipe is more horizontal or vertical
        if(Math.abs(deltaX) > Math.abs(deltaY)) {
            // Horizontal swipe
            if(deltaX > 0) {
                this.move('right');
            } else {
                this.move('left');
            }
        } else {
            // Vertical swipe
            if(deltaY > 0) {
                this.move('down');
            } else {
                this.move('up');
            }
        }
    }
    
    startGame() {
        this.grid = Array(this.size).fill(null).map(() => Array(this.size).fill(0));
        this.score = 0;
        this.gameOver = false;
        this.won = false;
        this.startTime = Date.now();
        this.gameTime = 0;
        
        this.updateScore();
        this.hideMessage();
        
        this.addRandomTile();
        this.addRandomTile();
        this.render();
    }
    
    addRandomTile() {
        const empty = [];
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                if(this.grid[i][j] === 0) {
                    empty.push({row: i, col: j});
                }
            }
        }
        
        if(empty.length > 0) {
            const pos = empty[Math.floor(Math.random() * empty.length)];
            this.grid[pos.row][pos.col] = Math.random() < 0.9 ? 2 : 4;
        }
    }
    
    move(direction) {
        let moved = false;
        let merged = false;
        const oldGrid = JSON.parse(JSON.stringify(this.grid));
        
        // Store score before move to detect merges
        const oldScore = this.score;
        
        switch(direction) {
            case 'up':
                moved = this.moveUp();
                break;
            case 'down':
                moved = this.moveDown();
                break;
            case 'left':
                moved = this.moveLeft();
                break;
            case 'right':
                moved = this.moveRight();
                break;
        }
        
        // Check if tiles merged (score increased)
        merged = this.score > oldScore;
        
        if(moved) {
            // Play sounds
            if(merged) {
                this.playSound('merge');
            } else {
                this.playSound('move');
            }
            
            this.addRandomTile();
            this.playSound('spawn');
            this.render();
            
            if(this.checkWin()) {
                this.showMessage('You Win! 🎉', 'win');
                this.playSound('win');
                this.saveScore(); // Save score on win!
            } else if(this.checkGameOver()) {
                this.gameOver = true;
                this.showMessage('Game Over!', 'game-over');
                this.playSound('gameover');
                this.saveScore();
            }
        }
    }
    
    moveLeft() {
        let moved = false;
        for(let i = 0; i < this.size; i++) {
            const row = this.grid[i].filter(val => val !== 0);
            const merged = this.mergeTiles(row);
            const newRow = merged.concat(Array(this.size - merged.length).fill(0));
            
            if(JSON.stringify(this.grid[i]) !== JSON.stringify(newRow)) {
                moved = true;
            }
            this.grid[i] = newRow;
        }
        return moved;
    }
    
    moveRight() {
        let moved = false;
        for(let i = 0; i < this.size; i++) {
            const row = this.grid[i].filter(val => val !== 0).reverse();
            const merged = this.mergeTiles(row);
            const newRow = Array(this.size - merged.length).fill(0).concat(merged.reverse());
            
            if(JSON.stringify(this.grid[i]) !== JSON.stringify(newRow)) {
                moved = true;
            }
            this.grid[i] = newRow;
        }
        return moved;
    }
    
    moveUp() {
        this.transpose();
        const moved = this.moveLeft();
        this.transpose();
        return moved;
    }
    
    moveDown() {
        this.transpose();
        const moved = this.moveRight();
        this.transpose();
        return moved;
    }
    
    transpose() {
        const newGrid = Array(this.size).fill(null).map(() => Array(this.size).fill(0));
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                newGrid[j][i] = this.grid[i][j];
            }
        }
        this.grid = newGrid;
    }
    
    mergeTiles(row) {
        const result = [];
        let i = 0;
        
        while(i < row.length) {
            if(i + 1 < row.length && row[i] === row[i + 1]) {
                const merged = row[i] * 2;
                result.push(merged);
                this.score += merged;
                this.updateScore();
                i += 2;
            } else {
                result.push(row[i]);
                i++;
            }
        }
        
        return result;
    }
    
    checkWin() {
        if(this.won) return false;
        
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                if(this.grid[i][j] === 2048) {
                    this.won = true;
                    return true;
                }
            }
        }
        return false;
    }
    
    checkGameOver() {
        // Check for empty cells
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                if(this.grid[i][j] === 0) return false;
            }
        }
        
        // Check for possible merges
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                const current = this.grid[i][j];
                
                // Check right
                if(j < this.size - 1 && current === this.grid[i][j + 1]) {
                    return false;
                }
                
                // Check down
                if(i < this.size - 1 && current === this.grid[i + 1][j]) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    render() {
        const container = document.getElementById('tile-container');
        container.innerHTML = '';
        
        for(let i = 0; i < this.size; i++) {
            for(let j = 0; j < this.size; j++) {
                const value = this.grid[i][j];
                if(value !== 0) {
                    const tile = this.createTile(value, i, j);
                    container.appendChild(tile);
                }
            }
        }
    }
    
    createTile(value, row, col) {
        const tile = document.createElement('div');
        tile.className = `tile tile-${value} tile-position-${row}-${col}`;
        tile.textContent = value;
        return tile;
    }
    
    updateScore() {
        document.getElementById('current-score').textContent = this.score;
        
        if(this.score > this.bestScore) {
            this.bestScore = this.score;
            document.getElementById('best-score').textContent = this.bestScore;
        }
    }
    
    showMessage(text, className) {
        const message = document.getElementById('game-message');
        message.querySelector('p').textContent = text;
        message.className = 'game-message ' + className;
        message.style.display = 'flex';
    }
    
    hideMessage() {
        const message = document.getElementById('game-message');
        message.style.display = 'none';
    }
    
    saveScore() {
        if(this.score === 0) return;
        
        // Calculate game time in seconds
        this.gameTime = Math.floor((Date.now() - this.startTime) / 1000);
        
        const data = new FormData();
        data.append('score', this.score);
        data.append('game_time', this.gameTime);
        
        fetch(saveScoreUrl, {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                console.log('Score saved:', data.score);
            }
        })
        .catch(error => {
            console.error('Error saving score:', error);
        });
    }
}

// Initialize game when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if(document.getElementById('grid-container')) {
        window.game = new Game2048();
    }
});
