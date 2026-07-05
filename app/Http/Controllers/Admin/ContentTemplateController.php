<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentTemplate;
use Illuminate\Http\Request;

/**
 * Library of reusable product-page "story section" templates. The section
 * builder here is the same component used on the product edit page.
 */
class ContentTemplateController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.content-templates.index', [
            'templates' => ContentTemplate::latest()->get(),
            'editing' => $request->filled('edit') ? ContentTemplate::find($request->query('edit')) : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        ContentTemplate::create([
            'name' => $data['name'],
            'sections' => ContentTemplate::cleanSections(json_decode((string) $request->input('sections_json'), true)),
        ]);

        return redirect()->route('admin.content-templates.index')->with('success', 'Template created.');
    }

    public function update(Request $request, ContentTemplate $template)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $template->update([
            'name' => $data['name'],
            'sections' => ContentTemplate::cleanSections(json_decode((string) $request->input('sections_json'), true)),
        ]);

        return redirect()->route('admin.content-templates.index')->with('success', 'Template updated.');
    }

    public function destroy(ContentTemplate $template)
    {
        $template->delete();

        return back()->with('success', 'Template deleted.');
    }
}
