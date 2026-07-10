<?php
// Rewrite probe used by the setup wizard's AllowOverride self-test.
// Reached via /setup/probe-check (no .php) if mod_rewrite + AllowOverride are working.
// This file is harmless; delete the setup/ directory after installation as instructed.
header('Content-Type: text/plain');
echo 'PROBE_OK';
