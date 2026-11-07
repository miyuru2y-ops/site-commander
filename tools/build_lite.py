#!/usr/bin/env python3
"""
Build "Siteloom" (the free edition) for WordPress.org from this repository.

The Lite edition is fully free: no licensing code, no Freemius SDK, and none of the
administrative tools (plugin/theme management, updates, code snippets, theme file
editing, users, database and options tools, client sites). Code for those is wrapped in
// @pro-start ... // @pro-end in the source and removed here. Names are re-prefixed
(SC_/sc_ -> SITELOOM_/siteloom_) and the REST namespace becomes siteloom/v1 so
Lite and Pro never collide.

Usage: python3 tools/build_lite.py            -> build/siteloom.zip
"""
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(ROOT, 'build')
SLUG = 'siteloom'
OUT = os.path.join(OUT_DIR, SLUG)

SKIP_DIRS = {'.git', '.github', 'build', 'tools', 'assets', os.path.join('includes', 'freemius')}
SKIP_FILES = {'README.md', '.gitignore', os.path.join('includes', 'class-sc-hub.php'), os.path.join('includes', 'class-sc-license.php'), 'readme.txt', 'site-commander.php'}

LITE_CARD = '''			<div class="sc-card" id="plan">
				<h2>3. Siteloom</h2>
				<p>Everything in this plugin is free to use. Siteloom works on content, media, SEO, blocks, menus, forms and more, and leaves plugins, themes, updates, users and code to you.</p>
				<p class="sc-sub">Need an AI that can also install plugins, run updates or manage several client sites? That is a separate paid plugin from the same author, at <a href="https://getsitecommander.com/" target="_blank" rel="noopener">getsitecommander.com</a>.</p>
			</div>'''

HEADER = '''<?php
/**
 * Plugin Name:       Siteloom
 * Description:       Let any AI (Claude, ChatGPT, Cursor, n8n) work on your WordPress content, media and SEO through MCP or a REST API, with a separate key per AI, an activity log, undo and approval.
 * Version:           {version}
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Miyuru Gomes
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteloom
 *
 * @package Siteloom
 */
'''

UNINSTALL = '''<?php
/**
 * Removes Siteloom settings when the plugin is deleted. SEO fields saved on posts are kept.
 *
 * @package Siteloom
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'siteloom_settings', 'siteloom_keys', 'siteloom_key_hash', 'siteloom_key_hint', 'siteloom_activity_log', 'siteloom_404_log', 'siteloom_redirects', 'siteloom_robots_extra', 'siteloom_home_seo', 'siteloom_guard_report', 'siteloom_guard_admins', 'siteloom_pending', 'siteloom_webhooks', 'siteloom_key_usage', 'siteloom_report_last' ) as $siteloom_opt ) {
	delete_option( $siteloom_opt );
}
foreach ( (array) get_option( 'siteloom_undo_index', array() ) as $siteloom_uid ) {
	delete_option( 'siteloom_undo_' . $siteloom_uid );
}
delete_option( 'siteloom_undo_index' );
wp_clear_scheduled_hook( 'siteloom_guard_scan' );
wp_clear_scheduled_hook( 'siteloom_report_check' );
'''


def strip_pro(text, path):
    out, skip = [], 0
    for line in text.split('\n'):
        if '@pro-start' in line:
            skip += 1
            continue
        if '@pro-end' in line:
            skip -= 1
            continue
        if skip:
            continue
        m = re.match(r'(\s*)// @lite: (.*)$', line)
        if m:
            out.append(m.group(1) + m.group(2))
            continue
        if '@lite-card' in line:
            out.append(LITE_CARD)
            continue
        out.append(line)
    if skip:
        sys.exit('Unbalanced @pro markers in ' + path)
    return '\n'.join(out)


def rename(text):
    text = text.replace("'admin_post_sc_action'", "'admin_post_siteloom_action'")
    text = text.replace("'x_sc_key'", "'x_siteloom_key'")
    text = re.sub(r'(?<![A-Za-z0-9_$\\-])SC_', 'SITELOOM_', text)
    text = re.sub(r'(?<![A-Za-z0-9_$\\-])sc_', 'siteloom_', text)
    text = re.sub(r'(?<![\w.-])site-commander(?![\w-])', SLUG, text)
    text = text.replace('@package SiteCommander\n', '@package Siteloom\n')
    text = text.replace('Site Commander', 'Siteloom').replace('SiteCommander', 'Siteloom')
    text = text.replace('site-commander-backups', 'siteloom-backups').replace("'site_commander'", "'siteloom'")
    return text


def main():
    src_main = open(os.path.join(ROOT, 'site-commander.php')).read()
    version = re.search(r"Version:\s*([0-9.]+)", src_main).group(1)
    if os.path.exists(OUT):
        shutil.rmtree(OUT)
    os.makedirs(OUT)

    for dirpath, dirnames, filenames in os.walk(ROOT):
        rel = os.path.relpath(dirpath, ROOT)
        rel = '' if rel == '.' else rel
        dirnames[:] = [d for d in dirnames if os.path.join(rel, d) not in SKIP_DIRS and not d.startswith('.') and os.path.join(rel, d) not in SKIP_DIRS]
        for f in filenames:
            rf = os.path.join(rel, f)
            if f.startswith('.') or rf in SKIP_FILES:
                continue
            dst = os.path.join(OUT, rf)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            if f.endswith('.php'):
                t = rename(strip_pro(open(os.path.join(dirpath, f)).read(), rf))
                open(dst, 'w').write(t)
            elif f.endswith('.css'):
                open(dst, 'w').write(open(os.path.join(dirpath, f)).read().replace('Site Commander', 'Siteloom'))
            else:
                shutil.copy2(os.path.join(dirpath, f), dst)

    # Main file: new header, no Hub, no licensing.
    body = src_main.split('*/', 1)[1]
    body = '\n'.join(l for l in body.split('\n') if 'class-sc-hub.php' not in l and 'class-sc-license.php' not in l)
    main_php = HEADER.format(version=version) + rename(strip_pro(body, 'site-commander.php'))
    open(os.path.join(OUT, SLUG + '.php'), 'w').write(main_php)
    open(os.path.join(OUT, 'uninstall.php'), 'w').write(UNINSTALL)
    shutil.copy2(os.path.join(ROOT, 'tools', 'lite-readme.txt'), os.path.join(OUT, 'readme.txt'))
    rd = open(os.path.join(OUT, 'readme.txt')).read().replace('{version}', version)
    open(os.path.join(OUT, 'readme.txt'), 'w').write(rd)

    # Sanity checks.
    bad = []
    for dirpath, _, filenames in os.walk(OUT):
        for f in filenames:
            if not f.endswith('.php'):
                continue
            p = os.path.join(dirpath, f)
            t = open(p).read()
            for needle in ('SC_License', 'SITELOOM_License', 'sc_fs', 'siteloom_fs', 'Site Commander', 'SiteCommander', 'freemius', 'Freemius', 'SITELOOM_Hub', '@pro-', 'write_theme_file', 'save_code_snippet', 'install_plugin', 'database_search_replace', 'wp_insert_user'):
                if needle in t:
                    bad.append(os.path.relpath(p, OUT) + ': ' + needle)
            r = subprocess.run(['php', '-l', p], capture_output=True, text=True)
            if r.returncode:
                bad.append(r.stdout + r.stderr)
    if bad:
        print('\n'.join(bad))
        sys.exit('Lite build check failed.')

    zip_path = os.path.join(OUT_DIR, SLUG + '.zip')
    if os.path.exists(zip_path):
        os.remove(zip_path)
    subprocess.run(['zip', '-qr', SLUG + '.zip', SLUG], cwd=OUT_DIR, check=True)
    print('Built ' + zip_path + ' (v' + version + ')')


if __name__ == '__main__':
    main()
