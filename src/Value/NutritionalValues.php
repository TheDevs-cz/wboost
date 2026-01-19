<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class NutritionalValues
{
    public function __construct(
        public null|string $energyValue = null,
        public null|string $fats = null,
        public null|string $carbohydrates = null,
        public null|string $proteins = null,
    ) {
    }

    public function add(NutritionalValues $other): self
    {
        return new self(
            energyValue: $this->addValues($this->energyValue, $other->energyValue),
            fats: $this->addValues($this->fats, $other->fats),
            carbohydrates: $this->addValues($this->carbohydrates, $other->carbohydrates),
            proteins: $this->addValues($this->proteins, $other->proteins),
        );
    }

    public function isComplete(): bool
    {
        return $this->energyValue !== null
            && $this->fats !== null
            && $this->carbohydrates !== null
            && $this->proteins !== null;
    }

    public function isEmpty(): bool
    {
        return $this->energyValue === null
            && $this->fats === null
            && $this->carbohydrates === null
            && $this->proteins === null;
    }

    public function hasAnyValue(): bool
    {
        return !$this->isEmpty();
    }

    public function formatEnergyValue(): string
    {
        if ($this->energyValue === null) {
            return '-';
        }

        return $this->formatNumber($this->energyValue) . ' kJ';
    }

    public function formatFats(): string
    {
        if ($this->fats === null) {
            return '-';
        }

        return $this->formatNumber($this->fats) . ' g';
    }

    public function formatCarbohydrates(): string
    {
        if ($this->carbohydrates === null) {
            return '-';
        }

        return $this->formatNumber($this->carbohydrates) . ' g';
    }

    public function formatProteins(): string
    {
        if ($this->proteins === null) {
            return '-';
        }

        return $this->formatNumber($this->proteins) . ' g';
    }

    public function formatCompact(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $parts = [];

        if ($this->energyValue !== null) {
            $parts[] = $this->formatNumber($this->energyValue) . ' kJ';
        }
        if ($this->fats !== null) {
            $parts[] = 'T: ' . $this->formatNumber($this->fats) . ' g';
        }
        if ($this->carbohydrates !== null) {
            $parts[] = 'S: ' . $this->formatNumber($this->carbohydrates) . ' g';
        }
        if ($this->proteins !== null) {
            $parts[] = 'B: ' . $this->formatNumber($this->proteins) . ' g';
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<NutritionalValues> $values
     * @return array{min: NutritionalValues, max: NutritionalValues}
     */
    public static function range(array $values): array
    {
        if (count($values) === 0) {
            return [
                'min' => new self(),
                'max' => new self(),
            ];
        }

        $minEnergy = null;
        $maxEnergy = null;
        $minFats = null;
        $maxFats = null;
        $minCarbs = null;
        $maxCarbs = null;
        $minProteins = null;
        $maxProteins = null;

        foreach ($values as $value) {
            if ($value->energyValue !== null) {
                $minEnergy = $minEnergy === null ? $value->energyValue : ((float) $value->energyValue < (float) $minEnergy ? $value->energyValue : $minEnergy);
                $maxEnergy = $maxEnergy === null ? $value->energyValue : ((float) $value->energyValue > (float) $maxEnergy ? $value->energyValue : $maxEnergy);
            }
            if ($value->fats !== null) {
                $minFats = $minFats === null ? $value->fats : ((float) $value->fats < (float) $minFats ? $value->fats : $minFats);
                $maxFats = $maxFats === null ? $value->fats : ((float) $value->fats > (float) $maxFats ? $value->fats : $maxFats);
            }
            if ($value->carbohydrates !== null) {
                $minCarbs = $minCarbs === null ? $value->carbohydrates : ((float) $value->carbohydrates < (float) $minCarbs ? $value->carbohydrates : $minCarbs);
                $maxCarbs = $maxCarbs === null ? $value->carbohydrates : ((float) $value->carbohydrates > (float) $maxCarbs ? $value->carbohydrates : $maxCarbs);
            }
            if ($value->proteins !== null) {
                $minProteins = $minProteins === null ? $value->proteins : ((float) $value->proteins < (float) $minProteins ? $value->proteins : $minProteins);
                $maxProteins = $maxProteins === null ? $value->proteins : ((float) $value->proteins > (float) $maxProteins ? $value->proteins : $maxProteins);
            }
        }

        return [
            'min' => new self($minEnergy, $minFats, $minCarbs, $minProteins),
            'max' => new self($maxEnergy, $maxFats, $maxCarbs, $maxProteins),
        ];
    }

    public function equals(NutritionalValues $other): bool
    {
        return $this->compareValues($this->energyValue, $other->energyValue)
            && $this->compareValues($this->fats, $other->fats)
            && $this->compareValues($this->carbohydrates, $other->carbohydrates)
            && $this->compareValues($this->proteins, $other->proteins);
    }

    private function addValues(null|string $a, null|string $b): null|string
    {
        if ($a === null && $b === null) {
            return null;
        }

        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return number_format((float) $a + (float) $b, 2, '.', '');
    }

    private function compareValues(null|string $a, null|string $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return abs((float) $a - (float) $b) < 0.001;
    }

    private function formatNumber(string $value): string
    {
        $floatValue = (float) $value;
        $intValue = (int) $floatValue;

        if ((float) $intValue === $floatValue) {
            return number_format($intValue, 0, ',', ' ');
        }

        return number_format($floatValue, 1, ',', ' ');
    }
}
