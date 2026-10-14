<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Domain;

enum Kind: string
{
    case TrainingCrawler = 'training_crawler';
    case SearchCrawler = 'search_crawler';
    case UserFetch = 'user_fetch';
    case BrowsingAgent = 'browsing_agent';
    case AutomationUnknown = 'automation_unknown';
    case HumanOrUnknown = 'human_or_unknown';
}
