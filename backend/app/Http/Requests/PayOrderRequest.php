<?php

namespace App\Http\Requests;

use App\Payments\Card;
use App\Payments\FakePaymentGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /orders/{id}/pay: card details for the simulated payment (D11, D34).
 *
 * Only the two test card numbers are accepted. The payment page says so and
 * checks it before sending, so a real card number should never get here; if
 * one does, it is rejected and never stored or logged (Laravel does not put
 * input values into validation messages unless asked to).
 */
class PayOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Spaces allowed: the form groups the digits in fours.
            'card_number' => ['required', 'string', 'max:23', 'regex:/^[0-9 ]+$/'],
            'card_expiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/'],
            'card_cvc' => ['required', 'string', 'regex:/^[0-9]{3}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'card_number.regex' => 'Enter the card number using digits only.',
            'card_expiry.regex' => 'Enter the expiry date as MM/YY.',
            'card_cvc.regex' => 'Enter the 3-digit security code.',
        ];
    }

    /** Checks that need the parsed values; run after the rules pass. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! in_array($this->digits(), FakePaymentGateway::TEST_CARDS, true)) {
                $validator->errors()->add('card_number',
                    'This is a simulated payment: only the test cards shown on the page work.');
            }
            [$month, $year] = $this->expiry();
            // A card is valid until the end of its expiry month.
            if (now()->startOfMonth()->gt(now()->setDate($year, $month, 1)->startOfMonth())) {
                $validator->errors()->add('card_expiry', 'This card has expired.');
            }
        }];
    }

    public function card(): Card
    {
        [$month, $year] = $this->expiry();

        return new Card($this->digits(), $month, $year, $this->validated('card_cvc'));
    }

    private function digits(): string
    {
        return str_replace(' ', '', (string) $this->input('card_number'));
    }

    /** @return array{int, int} month, four-digit year */
    private function expiry(): array
    {
        [$month, $year] = explode('/', (string) $this->input('card_expiry'));

        return [(int) $month, 2000 + (int) $year];
    }
}
