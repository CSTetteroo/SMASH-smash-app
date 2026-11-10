<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * BUas academies / study areas a member belongs to.
 */
enum Programme: string
{
    use HasOptions;

    case BuiltEnvironment = 'built_environment';
    case DataScienceAi = 'data_science_ai';
    case FacilityManagement = 'facility_management';
    case Games = 'games';
    case Hotel = 'hotel';
    case Imagineering = 'imagineering';
    case LeisureEvents = 'leisure_events';
    case Logistics = 'logistics';
    case MediaCreativeBusiness = 'media_creative_business';
    case Tourism = 'tourism';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::BuiltEnvironment => 'Built Environment',
            self::DataScienceAi => 'Data Science & AI',
            self::FacilityManagement => 'Facility Management',
            self::Games => 'Games',
            self::Hotel => 'Hotel',
            self::Imagineering => 'Imagineering',
            self::LeisureEvents => 'Leisure & Events',
            self::Logistics => 'Logistics',
            self::MediaCreativeBusiness => 'Media & Creative Business',
            self::Tourism => 'Tourism',
            self::Staff => 'BUas staff',
        };
    }
}
