<?php

namespace Database\Seeders\Demo;

use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use Illuminate\Support\Str;

/**
 * The 66 fictional members of SMASH, in user id order: the two demo accounts first, then the
 * committee, then everybody else roughly in the order they signed up. Emails are @smash.test only.
 *
 * Each player's hidden true skill comes from their level (± noise, doubles correlated with
 * singles) unless the roster pins it for the story (the demo accounts, the top players).
 */
final class Roster
{
    /**
     * True singles skill by level (Elo scale), before individual noise.
     */
    private const LEVEL_SKILL = [
        'beginner' => 1240.0,
        'intermediate' => 1440.0,
        'advanced' => 1620.0,
        'competitive' => 1800.0,
    ];

    /**
     * Behaviour defaults by level: attendance, singles share, log rate.
     */
    private const LEVEL_DEFAULTS = [
        'beginner' => [0.34, 0.2, 0.7],
        'intermediate' => [0.42, 0.3, 0.9],
        'advanced' => [0.5, 0.45, 1.0],
        'competitive' => [0.58, 0.55, 1.15],
    ];

    /**
     * key => [name, programme, level, hand (R/L), joined week, options].
     * Options: admin, left (last week), new, attend, days, singles, log, skill [singles, doubles],
     * partner, predict [activity, sharpness], trend (skill per week), bio, email.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array<string, mixed>}>
     */
    private const PLAYERS = [
        'noor' => ['Noor van Dijk', 'leisure_events', 'competitive', 'R', -20, [
            'admin' => true, 'email' => 'admin@smash.test', 'attend' => 0.93, 'singles' => 0.55, 'log' => 1.45,
            'skill' => [1840, 1800], 'partner' => 'sanne', 'predict' => [0.62, 0.86],
            'bio' => 'Club chair and one of the founders. Played league badminton in Utrecht before moving to Breda. Ask me about the ladder.',
        ]],
        'lucas' => ['Lucas Janssen', 'games', 'advanced', 'L', -20, [
            'email' => 'member@smash.test', 'attend' => 0.8, 'singles' => 0.5, 'log' => 1.0,
            'skill' => [1610, 1630], 'trend' => 7.0, 'partner' => 'mateo', 'predict' => [0.3, 0.62],
            'bio' => 'Games student and proud left-hander. Still chasing the perfect jump smash.',
        ]],
        'daan' => ['Daan Visser', 'logistics', 'advanced', 'R', -19, [
            'admin' => true, 'attend' => 0.72, 'singles' => 0.35, 'predict' => [0.5, 0.74],
            'bio' => 'Treasurer. Keeps the shuttle budget in check and the drives low.',
        ]],
        'priya' => ['Priya Raman', 'data_science_ai', 'intermediate', 'R', -19, [
            'admin' => true, 'attend' => 0.78, 'singles' => 0.2, 'log' => 0.95, 'partner' => 'rohan', 'predict' => [0.85, 0.9],
            'bio' => 'Secretary and keeper of the club spreadsheets. Doubles over singles, every time.',
        ]],
        'sanne' => ['Sanne de Boer', 'hotel', 'advanced', 'R', -18, [
            'attend' => 0.74, 'singles' => 0.25, 'skill' => [1620, 1700], 'partner' => 'noor',
            'bio' => "Noor's doubles partner since the club's very first night.",
        ]],
        'mateo' => ['Mateo García', 'media_creative_business', 'advanced', 'R', -18, [
            'attend' => 0.74, 'singles' => 0.3, 'skill' => [1630, 1680], 'partner' => 'lucas',
            'bio' => 'Came to Breda on Erasmus from Valencia and decided to stay. Plays every rally like it is the last one.',
        ]],
        'wei' => ['Wei Chen', 'data_science_ai', 'competitive', 'R', -12, [
            'attend' => 0.72, 'singles' => 0.75, 'log' => 1.3, 'skill' => [1890, 1790],
            'bio' => 'Played provincial juniors in Guangzhou. Happy to share footwork drills with anyone who asks.',
        ]],
        'rizky' => ['Rizky Pratama', 'hotel', 'competitive', 'R', -12, [
            'attend' => 0.72, 'singles' => 0.3, 'log' => 1.3, 'skill' => [1810, 1920], 'partner' => 'putri',
            'bio' => 'From Bandung, where badminton is basically a religion.',
        ]],
        'putri' => ['Putri Wulandari', 'tourism', 'competitive', 'R', -12, [
            'attend' => 0.7, 'singles' => 0.25, 'log' => 1.3, 'skill' => [1760, 1900], 'partner' => 'rizky',
            'bio' => 'Doubles at heart. The front court is my happy place.',
        ]],
        'mads' => ['Mads Sørensen', 'imagineering', 'competitive', 'L', -10, [
            'attend' => 0.6, 'singles' => 0.65, 'skill' => [1830, 1790], 'partner' => 'freja',
            'bio' => 'Danish left-hander. Will talk your ear off about Viktor Axelsen.',
        ]],
        'arjun' => ['Arjun Mehta', 'games', 'competitive', 'R', -8, [
            'attend' => 0.64, 'singles' => 0.7, 'skill' => [1810, 1720],
        ]],
        'meiling' => ['Mei Ling Tan', 'leisure_events', 'competitive', 'R', -8, [
            'attend' => 0.6, 'singles' => 0.3, 'skill' => [1740, 1840], 'partner' => 'linh',
            'bio' => 'Grew up in Penang with a racket in my hand. Always up for mixed doubles.',
        ]],
        'jonas' => ['Jonas Weber', 'built_environment', 'competitive', 'R', -6, [
            'attend' => 0.55, 'singles' => 0.7, 'skill' => [1800, 1740],
            'bio' => 'Former junior league player from Hamburg. These days it is mostly for fun, and for the fries afterwards.',
        ]],
        'emre' => ['Emre Yılmaz', 'logistics', 'advanced', 'R', -10, [
            'attend' => 0.64, 'singles' => 0.5, 'partner' => 'elif',
        ]],
        'kacper' => ['Kacper Nowak', 'data_science_ai', 'advanced', 'R', -8, [
            'attend' => 0.55, 'singles' => 0.5, 'partner' => 'zuzanna', 'predict' => [0.45, 0.7],
        ]],
        'giulia' => ['Giulia Romano', 'imagineering', 'advanced', 'R', -6, [
            'attend' => 0.5, 'singles' => 0.5, 'predict' => [0.4, 0.66],
            'bio' => 'Smash first, ask questions later.',
        ]],
        'felix' => ['Felix Hoffmann', 'facility_management', 'advanced', 'R', -6, [
            'attend' => 0.55, 'singles' => 0.3, 'partner' => 'lena',
        ]],
        'margaux' => ['Margaux Dubois', 'staff', 'advanced', 'R', -12, [
            'attend' => 0.42, 'days' => 'thu', 'singles' => 0.3,
            'bio' => "Works at the Student Desk. Played in the French regional leagues for years and helps out at the beginners' clinics.",
        ]],
        'ruben' => ['Ruben Dekker', 'staff', 'intermediate', 'R', -11, [
            'attend' => 0.32, 'days' => 'thu', 'singles' => 0.25, 'predict' => [0.35, 0.5],
            'bio' => 'Runs the sports hall bookings, so be nice to me.',
        ]],
        'erik' => ['Erik Lindqvist', 'staff', 'intermediate', 'R', -9, [
            'attend' => 0.4, 'days' => 'tue', 'singles' => 0.3, 'predict' => [0.6, 0.78],
            'bio' => 'Logistics lecturer by day, net-kill enthusiast by night.',
        ]],
        'thijs' => ['Thijs Mulder', 'built_environment', 'intermediate', 'R', -8, [
            'attend' => 0.55, 'singles' => 0.25, 'partner' => 'fleur', 'predict' => [0.45, 0.6],
        ]],
        'fleur' => ['Fleur Hendriks', 'tourism', 'intermediate', 'R', -8, [
            'attend' => 0.55, 'singles' => 0.2, 'partner' => 'thijs', 'predict' => [0.4, 0.55],
            'bio' => 'Here for the rallies and the drinks afterwards.',
        ]],
        'rohan' => ['Rohan Kapoor', 'data_science_ai', 'intermediate', 'R', -6, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'priya', 'predict' => [0.6, 0.8],
        ]],
        'elif' => ['Elif Demir', 'imagineering', 'intermediate', 'R', -6, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'emre', 'predict' => [0.45, 0.6],
        ]],
        'kenji' => ['Kenji Watanabe', 'games', 'advanced', 'R', -2, [
            'left' => 6, 'attend' => 0.75, 'singles' => 0.65,
            'bio' => 'Exchange student from Osaka, here for the autumn block.',
        ]],
        'femke' => ['Femke Willems', 'hotel', 'intermediate', 'R', -2, [
            'left' => 7, 'attend' => 0.6, 'singles' => 0.45,
        ]],
        'oliver' => ['Oliver Bennett', 'games', 'intermediate', 'R', -2, [
            'left' => 3, 'attend' => 0.62, 'singles' => 0.5,
            'bio' => 'Off to an internship in Rotterdam, back on court next year.',
        ]],
        'ananya' => ['Ananya Iyer', 'tourism', 'advanced', 'R', -2, [
            'attend' => 0.5, 'singles' => 0.5,
        ]],
        'hao' => ['Hao Wang', 'games', 'advanced', 'L', -2, [
            'attend' => 0.55, 'singles' => 0.65,
            'bio' => 'Left-hander with a sneaky backhand drop.',
        ]],
        'elise' => ['Elise Peeters', 'leisure_events', 'advanced', 'R', -2, [
            'attend' => 0.45, 'singles' => 0.3,
        ]],
        'freja' => ['Freja Nielsen', 'media_creative_business', 'advanced', 'R', -2, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'mads',
        ]],
        'linh' => ['Linh Nguyen', 'hotel', 'advanced', 'R', -2, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'meiling', 'predict' => [0.3, 0.7],
        ]],
        'lena' => ['Lena Schmidt', 'logistics', 'advanced', 'R', -2, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'felix',
        ]],
        'lucia' => ['Lucía Fernández', 'hotel', 'intermediate', 'R', -2, [
            'attend' => 0.45, 'singles' => 0.25, 'predict' => [0.35, 0.72],
        ]],
        'marco' => ['Marco Bianchi', 'logistics', 'intermediate', 'R', -2, [
            'attend' => 0.45, 'singles' => 0.62, 'predict' => [0.4, 0.5],
        ]],
        'zuzanna' => ['Zuzanna Kowalska', 'media_creative_business', 'intermediate', 'R', -2, [
            'attend' => 0.5, 'singles' => 0.25, 'partner' => 'kacper', 'predict' => [0.5, 0.74],
        ]],
        'andrei' => ['Andrei Popescu', 'built_environment', 'advanced', 'R', 0, [
            'attend' => 0.5, 'singles' => 0.62, 'predict' => [0.35, 0.62],
        ]],
        'jiwoo' => ['Ji-woo Park', 'data_science_ai', 'advanced', 'R', 0, [
            'attend' => 0.5, 'singles' => 0.62, 'predict' => [0.5, 0.82],
        ]],
        'rafael' => ['Rafael Souza', 'leisure_events', 'advanced', 'R', 0, [
            'attend' => 0.45, 'singles' => 0.62,
            'bio' => 'Came for Leisure & Events, stayed for the drop shots.',
        ]],
        'aiman' => ['Aiman Hakim', 'facility_management', 'advanced', 'R', 0, [
            'attend' => 0.45, 'singles' => 0.6,
        ]],
        'bram' => ['Bram Smit', 'facility_management', 'intermediate', 'L', 0, [
            'attend' => 0.45, 'singles' => 0.3,
        ]],
        'camille' => ['Camille Laurent', 'leisure_events', 'intermediate', 'R', 0, [
            'attend' => 0.42, 'days' => 'tue', 'singles' => 0.25, 'predict' => [0.35, 0.7],
        ]],
        'hannah' => ['Hannah Becker', 'tourism', 'intermediate', 'R', 0, [
            'attend' => 0.45, 'singles' => 0.25, 'predict' => [0.55, 0.55],
        ]],
        'wout' => ['Wout Claes', 'games', 'intermediate', 'R', 0, [
            'attend' => 0.45, 'singles' => 0.5, 'predict' => [0.5, 0.42],
            'bio' => 'Self-appointed club DJ. Requests via the group chat.',
        ]],
        'chidi' => ['Chidi Okafor', 'built_environment', 'intermediate', 'R', 0, [
            'attend' => 0.4, 'singles' => 0.5, 'predict' => [0.4, 0.6],
        ]],
        'pablo' => ['Pablo Ruiz', 'leisure_events', 'intermediate', 'R', 0, [
            'attend' => 0.4, 'singles' => 0.3, 'predict' => [0.35, 0.4],
        ]],
        'yassine' => ['Yassine El Amrani', 'logistics', 'intermediate', 'R', 0, [
            'attend' => 0.42, 'days' => 'tue', 'singles' => 0.3, 'predict' => [0.45, 0.58],
        ]],
        'lotte' => ['Lotte Vermeulen', 'leisure_events', 'beginner', 'R', 0, [
            'attend' => 0.42, 'trend' => 6.0, 'predict' => [0.4, 0.45],
            'bio' => 'Picked up a racket in September and never looked back.',
        ]],
        'gabija' => ['Gabija Petrauskaitė', 'tourism', 'beginner', 'R', 0, []],
        'olena' => ['Olena Shevchenko', 'media_creative_business', 'beginner', 'R', 0, []],
        'minh' => ['Minh Tran', 'logistics', 'intermediate', 'R', 1, [
            'attend' => 0.42, 'singles' => 0.5, 'predict' => [0.4, 0.68],
        ]],
        'ines' => ['Inês Costa', 'tourism', 'intermediate', 'R', 1, [
            'attend' => 0.4, 'singles' => 0.25, 'predict' => [0.3, 0.6],
        ]],
        'aoife' => ['Aoife Murphy', 'media_creative_business', 'intermediate', 'R', 1, [
            'attend' => 0.42, 'days' => 'thu', 'singles' => 0.25, 'predict' => [0.5, 0.5],
        ]],
        'bea' => ['Bea Santos', 'hotel', 'intermediate', 'L', 1, [
            'attend' => 0.4, 'singles' => 0.25, 'predict' => [0.3, 0.65],
        ]],
        'bence' => ['Bence Tóth', 'games', 'beginner', 'R', 1, []],
        'nattapong' => ['Nattapong Srisuk', 'hotel', 'beginner', 'R', 1, []],
        'valeria' => ['Valeria Morales', 'imagineering', 'beginner', 'R', 2, [
            'predict' => [0.3, 0.52],
        ]],
        'jesse' => ['Jesse van Leeuwen', 'facility_management', 'beginner', 'R', 3, [
            'attend' => 0.4,
        ]],
        'dewi' => ['Dewi Anggraini', 'tourism', 'intermediate', 'R', 4, [
            'attend' => 0.42, 'singles' => 0.25, 'trend' => 6.0,
            'bio' => 'Back on court after a few years off. Joined in October.',
        ]],
        'arne' => ['Arne Maes', 'built_environment', 'advanced', 'L', 5, [
            'attend' => 0.4, 'singles' => 0.3,
        ]],
        'moritz' => ['Moritz Schäfer', 'imagineering', 'advanced', 'R', 6, [
            'attend' => 0.5, 'singles' => 0.65, 'trend' => 4.0,
        ]],
        'iris' => ['Iris Kuijpers', 'media_creative_business', 'intermediate', 'R', 6, [
            'attend' => 0.45, 'singles' => 0.25, 'trend' => 4.0,
        ]],
        'sofia' => ['Sofia Marino', 'tourism', 'beginner', 'R', 99, ['new' => true]],
        'tom' => ['Tom Peeters', 'logistics', 'intermediate', 'R', 99, ['new' => true]],
        'yara' => ['Yara Haddad', 'data_science_ai', 'beginner', 'R', 99, ['new' => true]],
        'lukas' => ['Lukas Novák', 'games', 'intermediate', 'R', 99, ['new' => true]],
    ];

    /**
     * Profiles in user id order, keyed by player key.
     *
     * @return array<string, PlayerProfile>
     */
    public static function build(Rng $rng): array
    {
        $profiles = [];

        foreach (self::PLAYERS as $key => $row) {
            [$name, $programme, $level, $hand, $joined, $options] = $row;
            [$attend, $singles, $log] = self::LEVEL_DEFAULTS[$level];

            // Noise is always drawn, so pinning one player's skill never shifts anybody else's.
            $singlesNoise = max(-95.0, min(95.0, $rng->gaussian(0.0, 45.0)));
            $doublesNoise = max(-90.0, min(90.0, $rng->gaussian(0.0, 40.0)));
            $singlesSkill = self::LEVEL_SKILL[$level] + $singlesNoise;
            $doublesSkill = $singlesSkill + $doublesNoise;

            $pinned = self::floats($options['skill'] ?? null);

            if ($pinned !== null) {
                [$singlesSkill, $doublesSkill] = $pinned;
            }

            $predict = self::floats($options['predict'] ?? null) ?? [0.0, 0.5];
            $days = self::string($options['days'] ?? null) ?? 'both';

            $profiles[$key] = new PlayerProfile(
                key: $key,
                name: $name,
                email: self::string($options['email'] ?? null) ?? Str::slug(Str::ascii($name), '.').'@smash.test',
                programme: Programme::from($programme),
                level: SkillLevel::from($level),
                handedness: $hand === 'L' ? Handedness::Left : Handedness::Right,
                bio: self::string($options['bio'] ?? null),
                admin: ($options['admin'] ?? false) === true,
                joinedWeek: $joined,
                leftWeek: is_int($options['left'] ?? null) ? $options['left'] : null,
                isNew: ($options['new'] ?? false) === true,
                attendance: is_float($options['attend'] ?? null) ? $options['attend'] : $attend,
                days: in_array($days, ['tue', 'thu'], true) ? $days : 'both',
                singlesShare: is_float($options['singles'] ?? null) ? $options['singles'] : $singles,
                logRate: is_float($options['log'] ?? null) ? $options['log'] : $log,
                singlesSkill: $singlesSkill,
                doublesSkill: $doublesSkill,
                partnerKey: self::string($options['partner'] ?? null),
                predictActivity: $predict[0],
                predictSharpness: $predict[1],
                trend: is_float($options['trend'] ?? null) ? $options['trend'] : 0.0,
            );
        }

        return $profiles;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private static function floats(mixed $value): ?array
    {
        if (! is_array($value) || ! isset($value[0], $value[1]) || ! is_numeric($value[0]) || ! is_numeric($value[1])) {
            return null;
        }

        return [(float) $value[0], (float) $value[1]];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
