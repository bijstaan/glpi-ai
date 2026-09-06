<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpiai;

/**
 * A tool saying no for an ordinary reason.
 *
 * The distinction this draws is between "the model asked for something that
 * isn't there" and "the tool is broken". Both end up as an error result the
 * model can react to, but only the second is worth a line in GLPI's error log:
 * a model guessing a ticket id and missing is the loop working as designed, and
 * a log that fills up with those is a log nobody reads.
 *
 * So tools throw this for anything a better-informed caller could have avoided
 * — not found, not permitted, malformed argument — and let anything else
 * propagate as whatever it is.
 */
final class ToolException extends \RuntimeException
{
}
