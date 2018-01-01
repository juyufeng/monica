<?php

namespace App\Http\Controllers;

use Auth;
use App\Debt;
use App\Event;
use App\Contact;
use Carbon\Carbon;
use App\Helpers\DateHelper;
use Illuminate\Http\Request;
use App\Http\Resources\Contact\ContactShort as ContactShortResource;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $account = Auth::user()->account()
            ->withCount(
                'contacts', 'reminders', 'notes', 'activities', 'gifts', 'tasks'
            )->with('debts.contact')
            ->first();

        // Fetch last updated contacts
        $lastUpdatedContactsCollection = collect([]);
        $lastUpdatedContacts = $account->contacts()->where('is_partial', false)->latest('updated_at')->limit(10)->get();
        foreach ($lastUpdatedContacts as $contact) {
            $data = [
                'id' => $contact->id,
                'has_avatar' => $contact->has_avatar,
                'avatar_url' => $contact->getAvatarURL(),
                'initials' => $contact->getInitials(),
                'default_avatar_color' => $contact->default_avatar_color,
                'complete_name' => $contact->getCompleteName(auth()->user()->name_order),
            ];
            $lastUpdatedContactsCollection->push(json_encode($data));
        }

        // Latest statistics
        if ($account->contacts()->count() === 0) {
            return view('dashboard.blank');
        }

        $debt = $account->debts->where('status', 'inprogress');

        $debt_due = $debt->where('in_debt', 'yes')
            ->reduce(function ($totalDueDebt, Debt $debt) {
                return $totalDueDebt + $debt->amount;
            }, 0);

        $debt_owed = $debt->where('in_debt', 'no')
            ->reduce(function ($totalOwedDebt, Debt $debt) {
                return $totalOwedDebt + $debt->amount;
            }, 0);

        // List of events
        $events = $account->events()->limit(30)->get()
            ->reject(function (Event $event) {
                return $event->contact === null;
            })
            ->map(function (Event $event) use ($account) {
                return [
                    'id' => $event->id,
                    'date' => DateHelper::createDateFromFormat($event->created_at, auth()->user()->timezone),
                    'object' => $object ?? null,
                    'contact_id' => $event->contact->id,
                    'object_type' => $event->object_type,
                    'object_id' => $event->object_id,
                    'contact_complete_name' => $event->contact->getCompleteName(auth()->user()->name_order),
                    'nature_of_operation' => $event->nature_of_operation,
                ];
            });

        $data = [
            'events' => $events,
            'lastUpdatedContacts' => $lastUpdatedContactsCollection,
            'number_of_contacts' => $account->contacts_count,
            'number_of_reminders' => $account->reminders_count,
            'number_of_notes' => $account->notes_count,
            'number_of_activities' => $account->activities_count,
            'number_of_gifts' => $account->gifts_count,
            'number_of_tasks' => $account->tasks_count,
            'debt_due' => $debt_due,
            'debt_owed' => $debt_owed,
            'debts' => $debt,
            'user' => auth()->user(),
        ];

        return view('dashboard.index', $data);
    }

    /**
     * Get calls for the dashboard
     * @return Collection
     */
    public function calls()
    {
        $callsCollection = collect([]);
        $calls = auth()->user()->account->calls()->limit(15)->get();

        foreach ($calls as $call) {
            $data = [
                'id' => $call->id,
                'called_at' => \App\Helpers\DateHelper::getShortDate($call->called_at),
                'name' => $call->contact->getIncompleteName(),
                'contact_id' => $call->contact->id,
            ];
            $callsCollection->push($data);
        }

        return $callsCollection;
    }

    /**
     * Get notes for the dashboard
     * @return Collection
     */
    public function notes()
    {
        $notesCollection = collect([]);
        $notes = auth()->user()->account->notes()->favorited()->get();

        foreach ($notes as $note) {
            $data = [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => \App\Helpers\DateHelper::getShortDate($note->created_at),
                'name' => $note->contact->getIncompleteName(),
                'contact' => [
                    'contact_id' => $note->contact->id,
                    'has_avatar' => $note->contact->has_avatar,
                    'avatar_url' => $note->contact->getAvatarURL(),
                    'initials' => $note->contact->getInitials(),
                    'default_avatar_color' => $note->contact->default_avatar_color,
                    'complete_name' => $note->contact->getCompleteName(auth()->user()->name_order),
                ],
            ];
            $notesCollection->push($data);
        }

        return $notesCollection;
    }

    /**
     * Save the current active tab to the User table
     */
    public function setTab(Request $request)
    {
        auth()->user()->dashboard_active_tab = $request->get('tab');
        auth()->user()->save();
    }
}
