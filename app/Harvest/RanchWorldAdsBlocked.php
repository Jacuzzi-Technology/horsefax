<?php

namespace App\Harvest;

/** RanchWorldAds refused HorsefaxBot (403, 429 or a bot challenge). The run stops; it is never worked around. */
class RanchWorldAdsBlocked extends \RuntimeException {}
