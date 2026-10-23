<?php

declare(strict_types=1);

namespace App\Modules\Verification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\User;
use App\Modules\Verification\Actions\ReviewVerification;
use App\Modules\Verification\Actions\SubmitIdentityVerification;
use App\Modules\Verification\Http\Requests\SubmitVerificationRequest;
use App\Modules\Verification\Http\Resources\VerificationResource;
use App\Modules\Verification\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Verificação
 */
class VerificationController extends Controller
{
    /**
     * Submeter o BI para verificação (formador).
     */
    public function store(SubmitVerificationRequest $request, SubmitIdentityVerification $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $verification = $action->handle($user, (string) $request->validated('document_number'), $request->documentFiles());

        return (new VerificationResource($verification))->response()->setStatusCode(201);
    }

    /**
     * As minhas verificações.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return VerificationResource::collection(
            Verification::query()->where('user_id', $request->user()?->getAuthIdentifier())->latest()->get(),
        );
    }

    /**
     * Admin: verificações por estado (por defeito, pendentes).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status', Verification::STATUS_PENDING)->toString();

        return VerificationResource::collection(
            Verification::query()->with('user')->where('status', $status)->oldest()->paginate(20),
        );
    }

    /**
     * Admin: aprovar verificação.
     */
    public function approve(Request $request, Verification $verification, ReviewVerification $action): VerificationResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new VerificationResource($action->approve($verification, $admin)->load('user'));
    }

    /**
     * Admin: rejeitar verificação.
     */
    public function reject(Request $request, Verification $verification, ReviewVerification $action): VerificationResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);

        /** @var User $admin */
        $admin = $request->user();

        return new VerificationResource($action->reject($verification, $admin, $data['reason'])->load('user'));
    }
}
