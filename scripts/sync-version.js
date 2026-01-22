#!/usr/bin/env node
/**
 * Uppdatera och synkronisera version
 * 
 * Användning: npm run update
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const rootDir = path.join(__dirname, '..');
const packagePath = path.join(rootDir, 'package.json');
const pluginPath = path.join(rootDir, 'tranas-forms.php');

// Läs package.json
const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8'));

// Hämta nuvarande version och öka med 1
const currentVersion = parseInt(packageJson.version) || 0;
const newVersion = currentVersion + 1;

// Uppdatera package.json
packageJson.version = String(newVersion);
fs.writeFileSync(packagePath, JSON.stringify(packageJson, null, 4) + '\n', 'utf8');

// Läs och uppdatera tranas-forms.php
let pluginContent = fs.readFileSync(pluginPath, 'utf8');
pluginContent = pluginContent.replace(
    /^ \* Version: .+$/m,
    ` * Version: ${newVersion}`
);
fs.writeFileSync(pluginPath, pluginContent, 'utf8');

console.log(`✅ Version uppdaterad: ${currentVersion} → ${newVersion}`);
console.log(`   → package.json`);
console.log(`   → tranas-forms.php`);

// Skapa git commit, tag och push
try {
    execSync('git add package.json tranas-forms.php', { cwd: rootDir, stdio: 'pipe' });
    execSync(`git commit -m "v${newVersion}"`, { cwd: rootDir, stdio: 'pipe' });
    execSync(`git tag v${newVersion}`, { cwd: rootDir, stdio: 'pipe' });
    console.log(`   → git commit "v${newVersion}"`);
    console.log(`   → git tag v${newVersion}`);
    
    // Push commit och tags
    console.log(`\n📤 Pushar till remote...`);
    execSync('git push', { cwd: rootDir, stdio: 'pipe' });
    execSync('git push --tags', { cwd: rootDir, stdio: 'pipe' });
    console.log(`   → git push`);
    console.log(`   → git push --tags`);
    console.log(`\n🎉 Klart! Version ${newVersion} är live.`);
} catch (error) {
    console.log(`\n⚠️  Något gick fel: ${error.message}`);
}
