#!/usr/bin/env node
'use strict';

// Bumps the patch version of `public const VERSION = '<major>.<minor>.<patch>';` in
// src/Client.php. Used by .github/workflows/create-version-bump-pr.yml after a merge to main;
// .github/workflows/bump-version.yml reads the result with the same regular expression to create
// a tag and a release once the resulting pull request is merged.
//
// Usage: node .github/scripts/update-version.js

const fs = require('fs');
const path = require('path');

const CLIENT_PATH = path.join(__dirname, '..', '..', 'src', 'Client.php');
const VERSION_PATTERN = /public const VERSION = '([0-9]+)\.([0-9]+)\.([0-9]+)';/;

function main() {
    const source = fs.readFileSync(CLIENT_PATH, 'utf8');
    const match = source.match(VERSION_PATTERN);

    if (!match) {
        console.error(
            `Could not find "public const VERSION = '<major>.<minor>.<patch>';" in ${CLIENT_PATH}`,
        );
        process.exit(1);
    }

    const [declaration, major, minor, patch] = match;
    const oldVersion = `${major}.${minor}.${patch}`;
    const newVersion = `${major}.${minor}.${Number(patch) + 1}`;
    const newDeclaration = `public const VERSION = '${newVersion}';`;

    fs.writeFileSync(CLIENT_PATH, source.replace(declaration, newDeclaration));

    console.log(`old_version=${oldVersion}`);
    console.log(`new_version=${newVersion}`);

    const githubOutputPath = process.env.GITHUB_OUTPUT;
    if (githubOutputPath) {
        fs.appendFileSync(githubOutputPath, `old_version=${oldVersion}\nnew_version=${newVersion}\n`);
    }
}

main();
