<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $query = Item::with('user:id,name,student_id')
            ->where('status', 'ACTIVE')
            ->latest();

        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(function ($q) use ($s) {
                $q->where('item_name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhere('location', 'like', "%{$s}%")
                  ->orWhere('category', 'like', "%{$s}%");
            });
        }

        if ($request->filled('type') && strtoupper($request->type) !== 'ALL') {
            $query->where('type', strtoupper($request->type));
        }

        if ($request->filled('category') && strtoupper($request->category) !== 'ALL') {
            $query->where('category', $request->category);
        }

        $items = $query->paginate(50);

        return response()->json([
            'success' => true,
            'items' => $items->items(),
            'total' => $items->total(),
            'lost_count' => Item::where('type','LOST')->where('status','ACTIVE')->count(),
            'found_count' => Item::where('type','FOUND')->where('status','ACTIVE')->count(),
        ]);
    }

    public function store(Request $request)
    {
        abort_if($request->user()->role === 'visitor', 403, 'Visitors cannot report items.');

        $data = $request->validate([
            'item_name' => ['required','string','max:150'],
            'category' => ['required','string','max:80'],
            'description' => ['nullable','string','max:2000'],
            'location' => ['required','string','max:200'],
            'date' => ['required','date'],
            'type' => ['required','in:LOST,FOUND'],
            'contact' => ['nullable','string','max:200'],
            'image' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);

        $data['user_id'] = $request->user()->id;
        $data['status'] = 'ACTIVE';

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('items', 'public');
        }

        $item = Item::create($data)->load('user:id,name,student_id');

        Notification::create([
            'user_id' => $request->user()->id,
            'title' => 'Report submitted',
            'message' => "Your {$item->type} report for {$item->item_name} was submitted successfully.",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted.',
            'item' => $item
        ], 201);
    }

    public function show(Item $item)
    {
        return response()->json([
            'success' => true,
            'item' => $item->load('user:id,name,student_id')
        ]);
    }

    public function update(Request $request, Item $item)
    {
        abort_if($request->user()->role === 'visitor', 403, 'Visitors cannot edit items.');
        abort_unless($item->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'item_name' => ['required','string','max:150'],
            'category' => ['required','string','max:80'],
            'description' => ['nullable','string','max:2000'],
            'location' => ['required','string','max:200'],
            'date' => ['required','date'],
            'type' => ['required','in:LOST,FOUND'],
            'contact' => ['nullable','string','max:200'],
            'image' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);

        if ($request->hasFile('image')) {
            if ($item->image) {
                Storage::disk('public')->delete($item->image);
            }
            $data['image'] = $request->file('image')->store('items', 'public');
        }

        $item->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Report updated.',
            'item' => $item->load('user:id,name,student_id')
        ]);
    }

    public function myReports(Request $request)
    {
        $items = Item::where('user_id',$request->user()->id)
            ->with('user:id,name,student_id')
            ->latest()->get();

        return response()->json([
            'success' => true,
            'items' => $items,
            'total' => $items->count(),
            'lost_count' => $items->where('type','LOST')->count(),
            'found_count' => $items->where('type','FOUND')->count(),
        ]);
    }

    public function resolve(Request $request, Item $item)
    {
        abort_if($request->user()->role === 'visitor', 403, 'Visitors cannot resolve items.');
        abort_unless($item->user_id === $request->user()->id, 403);

        $item->update(['status' => 'RESOLVED']);

        Notification::create([
            'user_id' => $request->user()->id,
            'title' => 'Report resolved',
            'message' => "{$item->item_name} has been marked as resolved.",
        ]);

        return response()->json(['success'=>true,'message'=>'Report resolved.']);
    }

    public function destroy(Request $request, Item $item)
    {
        abort_if($request->user()->role === 'visitor', 403, 'Visitors cannot delete items.');
        abort_unless($item->user_id === $request->user()->id, 403);

        if ($item->image) {
            Storage::disk('public')->delete($item->image);
        }

        $item->delete();

        return response()->json(['success'=>true,'message'=>'Report deleted.']);
    }

    public function matches(Request $request, Item $item)
    {
        // Ownership check: Only the reporter can view possible matches for their item.
        abort_unless($item->user_id === $request->user()->id, 403, 'Unauthorized access to matches.');

        $opposite = $item->type === 'LOST' ? 'FOUND' : 'LOST';

        // Fetch all potential candidates of the opposite type that are ACTIVE
        $candidates = Item::where('id', '!=', $item->id)
            ->where('type', $opposite)
            ->where('status', 'ACTIVE')
            ->with('user:id,name,student_id')
            ->get();

        $scoredMatches = $candidates->map(function ($candidate) use ($item) {
            $score = 0;

            // 1. Category Match (High Importance)
            if ($candidate->category === $item->category) {
                $score += 30;
            }

            // 2. Item Name Similarity
            $name1 = strtolower($item->item_name);
            $name2 = strtolower($candidate->item_name);
            if ($name1 === $name2) {
                $score += 40;
            } elseif (str_contains($name1, $name2) || str_contains($name2, $name1)) {
                $score += 20;
            }

            // 3. Location Similarity
            $loc1 = strtolower($item->location);
            $loc2 = strtolower($candidate->location);
            if ($loc1 === $loc2) {
                $score += 25;
            } elseif (str_contains($loc1, $loc2) || str_contains($loc2, $loc1)) {
                $score += 15;
            }

            // 4. Date Proximity
            try {
                $date1 = \Carbon\Carbon::parse($item->date);
                $date2 = \Carbon\Carbon::parse($candidate->date);
                $diffDays = abs($date1->diffInDays($date2));
                if ($diffDays <= 3) {
                    $score += 10;
                } elseif ($diffDays <= 7) {
                    $score += 5;
                }
            } catch (\Exception $e) {}

            // 5. Description Similarity
            if ($item->description && $candidate->description) {
                $desc1 = strtolower($item->description);
                $desc2 = strtolower($candidate->description);
                if (str_contains($desc1, $desc2) || str_contains($desc2, $desc1)) {
                    $score += 5;
                }
            }

            $candidate->match_score = $score;
            return $candidate;
        });

        // Filter by threshold (e.g. 40 points) and sort by score
        $finalMatches = $scoredMatches->filter(fn($m) => $m->match_score >= 40)
            ->sortByDesc('match_score')
            ->take(15)
            ->values();

        return response()->json([
            'success' => true,
            'items' => $finalMatches,
            'total' => $finalMatches->count(),
            'lost_count' => 0,
            'found_count' => 0,
        ]);
    }
}

