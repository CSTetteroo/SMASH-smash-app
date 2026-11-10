<?php

namespace Database\Seeders;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * A handful of audited rating adjustments in the demo world: two placements, a correction for a
 * match played outside SMASH and a penalty for a tournament no-show. Every moment is derived from
 * the seeded history (first matches, the walkover) or from "now", like the demo world itself.
 *
 * Safe to run again: it does nothing when the log already has adjustments. Players it cannot find
 * are skipped. Ratings are recalculated once at the end.
 */
class RatingAdjustmentSeeder extends Seeder
{
    public function run(): void
    {
        if (RatingAdjustment::query()->exists()) {
            $this->command?->info('Rating adjustments already exist; nothing seeded.');

            return;
        }

        $now = CarbonImmutable::now();
        $admins = User::query()->admins()->orderBy('id')->get();
        $chair = $admins->first();
        $secretary = $admins->get(1) ?? $chair;

        // 1. Placement: an experienced newcomer, counted from just before his first singles match.
        if ($arne = $this->player('Arne Maes')) {
            $effective = RatingAdjustment::beforeFirstMatch($arne, Discipline::Singles);

            $this->record($arne, Discipline::Singles, 120,
                'Placement: four seasons of competitive singles at BC Leuven before joining SMASH. Counts from his first singles match.',
                $effective, $chair, $effective->addDay()->setTime(20, 30), $now);
        }

        // 2. Correction: an inter-university friendly against a non-member, which SMASH can't log as a match.
        if ($emre = $this->player('Emre Yılmaz')) {
            $effective = $now->subDays(14)->previous(CarbonInterface::SATURDAY)->setTime(15, 0);

            $created = $this->record($emre, Discipline::Singles, 16,
                'Correction: won 21–15, 21–18 against Avans Breda in the inter-university friendly. The opponent isn\'t a member, so the points were added by hand.',
                $effective, $secretary, $effective->addDays(3)->setTime(21, 5), $now);

            // Edited a day later (the score in the reason was fixed), so the log shows an edit.
            $created->forceFill(['updated_at' => $this->clamp($created->created_at?->addDay()->setTime(9, 40) ?? $now, $now)])->saveQuietly();
        }

        // 3. Penalty: the player who gave a walkover in a tournament without telling anyone.
        $walkover = BadmintonMatch::query()
            ->with(['tournament', 'entryA.player', 'entryB.player'])
            ->whereNotNull('tournament_id')
            ->where('status', MatchStatus::Walkover->value)
            ->whereNotNull('entry_a_id')
            ->whereNotNull('entry_b_id')
            ->whereNotNull('winning_side')
            ->orderBy('id')
            ->first();

        $noShow = $walkover?->winning_side === Side::A ? $walkover->entryB?->player : $walkover?->entryA?->player;

        if ($walkover !== null && $noShow !== null && $walkover->discipline === Discipline::Singles) {
            $effective = CarbonImmutable::instance($walkover->scheduled_at ?? $walkover->played_at ?? $now->subWeek());

            $this->record($noShow, Discipline::Singles, -15,
                'Penalty: no-show in the '.mb_strtolower($walkover->roundLabel() ?? 'first round').' of the '.$walkover->tournament->name.' without letting the committee know.',
                $effective, $chair, $effective->addDays(2)->setTime(19, 45), $now);
        }

        // 4. Placement for a brand-new member who hasn't played yet: it counts from the day it was added.
        if ($tom = $this->player('Tom Peeters')) {
            $effective = $this->clamp($now->subDay()->setTime(22, 40), $now);
            $effective = $tom->created_at !== null && $effective->lessThan($tom->created_at) ? CarbonImmutable::instance($tom->created_at) : $effective;

            $this->record($tom, Discipline::Doubles, 60,
                'Placement: two seasons of recreational league doubles in Eindhoven. Agreed after his trial evening.',
                $effective, $secretary, $effective, $now);
        }

        RatingEngine::recalculate();
    }

    private function player(string $name): ?User
    {
        return User::query()->where('name', $name)->first();
    }

    private function record(User $player, Discipline $discipline, float $amount, string $reason, CarbonImmutable $effective, ?User $admin, CarbonImmutable $created, CarbonImmutable $now): RatingAdjustment
    {
        $created = $this->clamp($created, $now);

        $adjustment = new RatingAdjustment([
            'user_id' => $player->id,
            'discipline' => $discipline,
            'amount' => $amount,
            'reason' => mb_substr($reason, 0, RatingAdjustment::REASON_LENGTH),
            'effective_at' => $this->clamp($effective, $now),
            'created_by' => $admin?->id,
        ]);
        $adjustment->created_at = $created;
        $adjustment->updated_at = $created;
        $adjustment->save();

        return $adjustment;
    }

    private function clamp(CarbonImmutable $moment, CarbonImmutable $now): CarbonImmutable
    {
        return $moment->greaterThan($now) ? $now : $moment;
    }
}
