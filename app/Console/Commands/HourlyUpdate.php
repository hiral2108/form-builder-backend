<?php

namespace App\Console\Commands;

use App\Libraries\Shopifyapi;
use App\Mail\MailTemplate;
use App\Models\AdminUser;
use App\Models\EmailTemplate;
use App\Models\Widget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HourlyUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:hourly-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset visitor counters, send the 7-day email, clean up uninstalled shops and flag expired subscriptions';

    /**
     * Execute the console command.
     *
     * Every job is date based (not time based), so each one must run at most once a day.
     */
    public function handle(): int
    {
        $this->resetVisitorCounters();
        $this->sendSevenDayEmails();
        $this->cleanUpUninstalledShops();
        $this->flagExpiredSubscriptions();

        return self::SUCCESS;
    }

    /**
     * Start a new visitor cycle for every active shop whose reset date has arrived.
     */
    private function resetVisitorCounters(): void
    {
        AdminUser::where('is_active', 1)
            ->whereDate('next_reset_date', '<=', now()->toDateString())
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $user->visitors = 0;
                    $user->next_reset_date = $user->plan_type === 'Yearly'
                        ? now()->addMonth()->toDateString()
                        : now()->addDays(30)->toDateString();
                    $user->max_visitors_at = null;
                    $user->save();
                }
            });
    }

    private function sendSevenDayEmails(): void
    {
        if (! config('services.slack.test_mail')) {
            return;
        }

        $template = EmailTemplate::where('key', 'AFTER_7_DAYS')->first();
        if (! $template) {
            return;
        }

        AdminUser::where('is_active', 1)
            ->whereNotNull('email')
            ->whereDate('created_at', now()->subDays(7)->toDateString())
            ->chunkById(100, function ($users) use ($template) {
                foreach ($users as $user) {
                    try {
                        Mail::to($user->email)->send(new MailTemplate($template, $user->email, $user->identifier));
                    } catch (\Throwable $e) {
                        Log::error('7-day email failed', ['shop' => $user->shop_url, 'error' => $e->getMessage()]);
                    }
                }
            });
    }

    /**
     * Shops that uninstalled 15 days ago have their forms marked as deleted.
     */
    private function cleanUpUninstalledShops(): void
    {
        AdminUser::where('is_active', 0)
            ->whereDate('updated_at', now()->subDays(15)->toDateString())
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    Widget::where('user_id', $user->id)
                        ->where('is_deleted', 0)
                        ->update([
                            'is_deleted' => 1,
                            'deleted_by' => $user->id,
                            'deleted_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Alert the team once when a shop's Shopify recurring charge has expired.
     */
    private function flagExpiredSubscriptions(): void
    {
        AdminUser::where('is_active', 1)
            ->where('platform', 'shopify')
            ->where('is_expired', 0)
            ->whereNotNull('charge_id')
            ->where('charge_id', '!=', '0')
            ->chunkById(50, function ($users) {
                foreach ($users as $user) {
                    try {
                        $api = new Shopifyapi([
                            'shop_domain' => $user->shop_url,
                            'token' => $user->token,
                            'api_key' => config('services.shopify.key'),
                            'secret' => config('services.shopify.secret'),
                        ]);
                        $charges = $api->call('GET', '/admin/api/'.config('services.shopify.version').'/recurring_application_charges.json');
                    } catch (\Throwable $e) {
                        // Uninstalled, revoked or rate-limited shops must not stop the rest of the run.
                        Log::warning('Subscription status check failed', ['shop' => $user->shop_url, 'error' => $e->getMessage()]);

                        continue;
                    }

                    foreach ((array) $charges as $charge) {
                        if (is_array($charge) && (string) ($charge['id'] ?? '') === (string) $user->charge_id && ($charge['status'] ?? '') === 'expired') {
                            $this->info('Expired subscription: '.$user->shop_url);

                            get_slack_message(
                                ':warning: '.config('services.shopify.name').': subscription expired',
                                $user->shop_url.' subscription expired',
                                "\nShop: ".$user->shop_url."\nEmail: ".$user->email."\nPlan: ".($charge['name'] ?? '')."\n",
                                '#e8a838'
                            );

                            $user->is_expired = 1;
                            $user->save();
                            break;
                        }
                    }

                    usleep(250000); // stay well under Shopify's API rate limit
                }
            });
    }
}
