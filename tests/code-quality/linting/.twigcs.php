<?php

use FriendsOfTwig\Twigcs\Config\Config;

// Twigcs 6.x Config exposes setName / setFinder / setSeverity / setReporter /
// setRuleset / setSpecificRulesets — there is no per-rule setter. Per-rule
// tuning would require a custom Ruleset class.
//
// The orchestrator (tests/code-quality/lint-orchestrator.js) invokes twigcs with
// explicit file paths on every call, so a setFinder is intentionally absent
// here — adding one made twigcs scan the project alongside the CLI path and
// produced thousands of unrelated violations.

$config = new Config('Drupal Twig Config');

// Severity threshold below which findings are suppressed. 'info' = surface
// everything; the orchestrator decides what to fail on.
$config->setSeverity('info');

return $config;
