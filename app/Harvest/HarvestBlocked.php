<?php

namespace App\Harvest;

/** A site blocked, rate-limited or kept failing: the run stops and reports it, and nothing works around it. */
class HarvestBlocked extends \RuntimeException {}
