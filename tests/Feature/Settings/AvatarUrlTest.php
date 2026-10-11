<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AvatarUrlTest extends TestCase
{
    public function test_deployed_avatar_url_uses_the_configured_backend_origin(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://ticketing-system-full.onrender.com']);
        URL::setRequest(Request::create('https://ticketing-system-full.pages.dev/settings/profile'));
        (new AppServiceProvider($this->app))->boot();

        $user = new User;
        $user->avatar_path = 'avatars/7/profile image.png';

        $this->assertSame(
            'https://ticketing-system-full.onrender.com/api/settings/profile/avatar?v=avatars%2F7%2Fprofile%20image.png',
            $user->avatarUrl(),
        );
        $this->assertSame('/api/settings/profile/avatar', parse_url($user->avatarUrl(), PHP_URL_PATH));
    }

    public function test_local_avatar_url_preserves_the_backend_ip_and_port(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        URL::setRequest(Request::create('http://192.168.100.51:8001/api/settings/profile'));

        $user = new User;
        $user->avatar_path = 'avatars/7/profile.png';

        $this->assertSame(
            'http://192.168.100.51:8001/api/settings/profile/avatar?v=avatars%2F7%2Fprofile.png',
            $user->avatarUrl(),
        );
    }

    public function test_users_without_an_avatar_keep_the_initials_fallback(): void
    {
        $this->assertNull((new User)->avatarUrl());
    }
}
