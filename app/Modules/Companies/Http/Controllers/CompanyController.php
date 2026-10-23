<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Actions\CreateCompany;
use App\Modules\Companies\Actions\ManageEmployees;
use App\Modules\Companies\Http\Requests\CompanyRequest;
use App\Modules\Companies\Http\Requests\EmployeeRequest;
use App\Modules\Companies\Http\Resources\CompanyResource;
use App\Modules\Companies\Http\Resources\EmployeeResource;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * @tags Empresas
 */
class CompanyController extends Controller
{
    /**
     * As minhas empresas (como gestor RH).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CompanyResource::collection(
            Company::query()->whereHas('managers', fn ($q) => $q->whereKey($user->id))->withCount('employees')->get(),
        );
    }

    /**
     * Registar empresa.
     *
     * O utilizador passa a gestor (perfil Empresa/RH).
     */
    public function store(CompanyRequest $request, CreateCompany $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string} $data */
        $data = $request->validated();

        return (new CompanyResource($action->handle($user, $data)))->response()->setStatusCode(201);
    }

    /**
     * Ver empresa.
     */
    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return new CompanyResource($company->loadCount('employees'));
    }

    /**
     * Atualizar empresa.
     */
    public function update(CompanyRequest $request, Company $company): CompanyResource
    {
        Gate::authorize('manage', $company);
        $company->fill($request->validated())->save();

        return new CompanyResource($company);
    }

    /**
     * Funcionários.
     *
     * Filtros opcionais: department, status, search (nome).
     */
    public function employees(Request $request, Company $company): AnonymousResourceCollection
    {
        Gate::authorize('manage', $company);

        $employees = $company->employees()
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')
            ->paginate(50);

        return EmployeeResource::collection($employees);
    }

    /**
     * Adicionar funcionário.
     */
    public function addEmployee(EmployeeRequest $request, Company $company, ManageEmployees $action): JsonResponse
    {
        Gate::authorize('manage', $company);
        /** @var array{name: string} $data */
        $data = $request->validated();

        return (new EmployeeResource($action->add($company, $data)))->response()->setStatusCode(201);
    }

    /**
     * Atualizar funcionário.
     */
    public function updateEmployee(EmployeeRequest $request, Company $company, CompanyEmployee $employee, ManageEmployees $action): EmployeeResource
    {
        Gate::authorize('manage', $company);
        abort_unless($employee->company_id === $company->id, 404);

        return new EmployeeResource($action->update($employee, $request->validated()));
    }

    /**
     * Remover funcionário.
     */
    public function removeEmployee(Company $company, CompanyEmployee $employee, ManageEmployees $action): Response
    {
        Gate::authorize('manage', $company);
        abort_unless($employee->company_id === $company->id, 404);

        $action->remove($employee);

        return response()->noContent();
    }
}
