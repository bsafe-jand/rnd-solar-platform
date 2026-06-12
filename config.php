<?php

/**
 * The public URL of this endpoint. Used in the "no data yet" message
 * and can be referenced in Node-RED configuration.
 */
define('PUSH_URL', 'http://192.168.0.3:9999/');

/**
 * Set this to any secret string.
 * The Node-RED flow must send the same value in the
 * X-Api-Key header, otherwise pushes are rejected.
 * Set to null or '' to disable authentication (not recommended).
 */
define('API_KEY', 'change-me-to-a-secret-key');

/**
 * How many log lines to keep in solar.log before rotating.
 * Each line is one push (one JSON object). At 30-second intervals
 * ~2880 lines ≈ 24 hours of history.
 */
define('LOG_MAX_LINES', 2880);
