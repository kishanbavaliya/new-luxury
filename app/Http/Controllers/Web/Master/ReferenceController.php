<?php

namespace App\Http\Controllers\Web\Master;

use App\Http\Controllers\Web\BaseController;
use Illuminate\Http\Request;
use App\Models\Admin\Reference;
use App\Base\Libraries\QueryFilter\QueryFilterContract;
use App\Base\Filters\Master\CommonMasterFilter;
use Illuminate\Support\Facades\Validator;

class ReferenceController extends BaseController
{
    protected $model;
    public function __construct(Reference $reference)
    {
        $this->model = $reference;

        // optional: require auth for create/update/delete
        $this->middleware('auth')->except(['index', 'show']);
    }

    public function index()
    {
        $page = trans('pages_names.view_reference');

        $main_menu = 'master';
        $sub_menu  = 'references';
        $references = Reference::paginate(10);

        return view('admin.master.references.index', compact('page', 'references', 'main_menu', 'sub_menu'));
    }

    public function show(Reference $reference)
    {
        $page = trans('pages_names.view_reference');

        $main_menu = 'master';
        $sub_menu  = 'references';

        return view('admin.master.references.show', compact('page', 'reference', 'main_menu', 'sub_menu'));
    }

    public function create()
    {
        $page = trans('pages_names.add_reference');

        $main_menu = 'master';
        $sub_menu  = 'references';

        return view('admin.master.references.create', compact('page', 'main_menu', 'sub_menu'));
    }

    public function fetch(QueryFilterContract $queryFilter)
    {
        $query = $this->model->query();//->active()
        $results = $queryFilter->builder($query)->customFilter(new CommonMasterFilter)->paginate();

        return view('admin.master.references._list', compact('results'));
    }

    public function store(Request $request)
    {
        Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
        ])->validate();

        $created = $this->model->create($request->only('name', 'short_name'));

        $message = trans('succes_messages.reference_added_succesfully') ?? 'Reference created successfully.';

        return redirect()->route('references.index')->with('success', $message);
    }

    public function edit(Reference $reference)
    {
        $page = trans('pages_names.edit_reference');

        $main_menu = 'master';
        $sub_menu  = 'references';

        return view('admin.master.references.edit', compact('page', 'reference', 'main_menu', 'sub_menu'));
    }

    public function update(Request $request, Reference $reference)
    {
        Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
        ])->validate();

        $reference->update($request->only('name', 'short_name'));

        $message = trans('succes_messages.reference_updated_succesfully') ?? 'Reference updated successfully.';

        return redirect()->route('references.index')->with('success', $message);
    }

    public function destroy(Reference $reference)
    {
        $reference->delete();

        $message = trans('succes_messages.reference_deleted_succesfully') ?? 'Reference deleted successfully.';

        return redirect()->route('references.index')->with('success', $message);
    }

    public function toggleStatus(Reference $reference)
    {
        $status = method_exists($reference, 'isActive') && $reference->isActive() ? false : true;
        $reference->update(['active' => $status]);

        $message = trans('succes_messages.reference_status_changed_succesfully') ?? 'Reference status changed successfully.';
        return redirect()->route('references.index')->with('success', $message);
    }

    public function delete(Reference $reference)
    {
        $reference->delete();

        $message = trans('succes_messages.reference_deleted_succesfully') ?? 'Reference deleted successfully.';
        return redirect()->route('references.index')->with('success', $message);
    }
}
