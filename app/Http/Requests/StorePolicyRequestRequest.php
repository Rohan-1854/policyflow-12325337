<?php

namespace App\Http\Requests;

use App\Domain\Pricing\PremiumCalculator;
use App\Domain\Pricing\PricingTable;
use App\Enums\MockScenario;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePolicyRequestRequest extends FormRequest
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]*$/';

    public function authorize(): bool
    {
        // Agent authentication is out of scope for the assignment (see README).
        return true;
    }

    /**
     * Normalise casing/whitespace so "plus" and "PLUS " are the same plan.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['client_request_id', 'agent_id', 'customer_name', 'date_of_birth', 'trip_start', 'trip_end'] as $field) {
            if (is_string($this->input($field))) {
                $normalised[$field] = trim($this->input($field));
            }
        }

        foreach (['destination', 'plan_code', 'mock_scenario'] as $field) {
            if (is_string($this->input($field))) {
                $normalised[$field] = strtoupper(trim($this->input($field)));
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $identifier = ['required', 'string', 'max:64', 'regex:'.self::IDENTIFIER_PATTERN];

        return [
            'client_request_id' => $identifier,
            'agent_id' => $identifier,
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'destination' => ['required', 'string', Rule::in(PricingTable::destinations())],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(PricingTable::planCodes())],
            'sum_insured' => ['required', 'integer', Rule::in(PricingTable::sumsInsured())],
            // Development-only switch that tells the mock insurer how to behave.
            'mock_scenario' => config('insurer.mock.enabled')
                ? ['nullable', 'string', Rule::in(MockScenario::values())]
                : ['prohibited'],
        ];
    }

    /**
     * Cross-field rules that need both dates to be valid first.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $errors = $validator->errors();

            if ($errors->hasAny(['date_of_birth', 'trip_start', 'trip_end'])) {
                return;
            }

            $dob = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input('date_of_birth'));
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input('trip_start'));
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input('trip_end'));

            if ($dob === false || $start === false || $end === false) {
                return;
            }

            if ($dob > $start) {
                $errors->add('date_of_birth', 'The date of birth must be on or before trip_start.');
            }

            $days = (int) $start->diff($end)->days + 1;

            if ($days > PricingTable::MAX_TRAVEL_DAYS) {
                $errors->add('trip_end', 'Trips longer than '.PricingTable::MAX_TRAVEL_DAYS.' days (inclusive) are not supported.');
            }

            // Belt and braces: make sure the calculator can price what we accept.
            if (! $errors->any()) {
                PremiumCalculator::ageOn($dob, $start);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_request_id.regex' => 'The client_request_id may contain only letters, digits, ".", "_", ":" and "-".',
            'agent_id.regex' => 'The agent_id may contain only letters, digits, ".", "_", ":" and "-".',
            'trip_end.after_or_equal' => 'The trip_end must be on or after trip_start.',
            'date_of_birth.before_or_equal' => 'The date_of_birth cannot be in the future.',
            'destination.in' => 'Unsupported destination. Supported: '.implode(', ', PricingTable::destinations()).'.',
            'plan_code.in' => 'Unsupported plan_code. Supported: '.implode(', ', PricingTable::planCodes()).'.',
            'sum_insured.in' => 'Unsupported sum_insured. Supported: '.implode(', ', PricingTable::sumsInsured()).'.',
            'mock_scenario.in' => 'Unknown mock_scenario. Supported: '.implode(', ', MockScenario::values()).'.',
            'mock_scenario.prohibited' => 'mock_scenario is only accepted in development environments.',
        ];
    }
}
