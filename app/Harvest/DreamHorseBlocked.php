<?php

namespace App\Harvest;

/** DreamHorse refused HorsefaxBot (403 or a bot challenge). The run stops; it is never worked around. */
class DreamHorseBlocked extends \RuntimeException {}
