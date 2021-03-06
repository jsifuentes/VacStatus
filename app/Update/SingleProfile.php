<?php namespace VacStatus\Update;

use VacStatus\Update\BaseUpdate;

use VacStatus\Models\Profile;
use VacStatus\Models\UserListProfile;
use VacStatus\Models\User;
use VacStatus\Models\ProfileBan;
use VacStatus\Models\ProfileOldAlias;

use Cache;
use Carbon;
use Auth;

use VacStatus\Steam\Steam;
use VacStatus\Steam\SteamAPI;

use DateTime;
use DateInterval;

/*

	STEPS TO GET PROFILE

*************************************************************************************************

	->	Check if the cache has expired

	->	REQUIRES UPDATE
		->	Request to STEAM WEB API
			(IT'S PROBABLY BETTER TO GRAB ALL THE API DATAS BEFORE DOING ANYTHING TO THE DB)
			->	'info'	->	reponse.players[0]
				->	profile.small_id 			->	toSmallId	->	"steamid"
				->	profile.privacy 			->				->	"communityvisibilitystate"
				->	profile.display_name		->				->	"personaname"
				->	profile.avatar 				->	see: [0]	->	"avatarfull"
				->	profile.avatar_thumb 		->	see: [0]	->	"avatar"
				->	profile.profile_created 	->	null(?)		->	"timecreated"
				->	profile_old_alias			-> (ADD DISPLAY NAME ONLY IF IT'S UNIQUE)
			->	'ban'	->	players[0]
				(MAKE SURE TO CHECK IF THE VALUE HAVE BEEN CHANGED BEFORE RETURNING)
				->	profile_ban.unban			->	COMPARE profile.vac AND "NumberOfVACBans"
				->	profile_ban.community 		->				->	"CommunityBanned"
				->	profile_ban.vac 			->				->	"NumberOfVACBans"
				->	profile_ban.vac_banned_on	->	see: [1]	->	"DaysSinceLastBan"
				->	profile_ban.trade 			->				->	"EconomyBan"
			->	'alias'
				(THIS ONE IS A VERY UNSTABLE API SO DON'T DIE WHEN IT DOESNT RESPOND)
				-> profile.alias 				->	see: [2]	->	(ALL OF IT)
		->	ADD ALL OF THE VALUES INTO AN ARRAY
			->	USE "RETURN FORMAT" AS REFERENCE
		->	UPDATE CACHE TO MAKE SURE THIS ISN'T CALLED AGAIN UNTIL TIME EXPIRES

	->	NO UPDATE
		->	DO A QUERY USING LEFT JOIN TO MAKE IT EFFICIENT AS POSSIBLE
			-> see: [3]
		->	MOVE THE VALUES FROM QUERY TO A NEW ARRAY
			->	USE "RETURN FORMAT" AS REFERENCE

	[0]: https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/models/Profile.php#L97
	[1]: https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/models/Profile.php#L115
	[2]: https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/models/Profile.php#L105
	[3]: https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/models/Profile.php#L187

 */

/*

	RETURN FORMAT

*************************************************************************************************

	return [
		profile.id
		profile.display_name
		profile.avatar
			-> this is the bigger one. other one is avatar_thumb
		profile.small_id
			-> make STEAM3ID by adding U:1: before the small_id
			-> convert to 32bit & 64bit ID
		profile.profile_created (CAN BE NULL)
			-> private profiles are NULL (UNLESS WE ALREADY HAD THEIR DATE)
		profile.privacy
			-> 1 - Private
			-> 2 - Friends only
			-> 3 - Public
		profile.alias
			-> convert from JSON to ARRAY
				json_encode($value)
			-> sort by time
				https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/Steam/SteamUser.php#L13
			-> conver time
				https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/Steam/SteamUser.php#L26
		profile.created_at
		profile_ban.vac
			-> this is the number of vac bans
		profile_ban.vac_banned_on
			-> see to convert date
				https://github.com/jung3o/VacStatus/tree/c6e626d8f8ab5f8c99db80f904275c185698c645/app/models/Profile.php#L131
		profile_ban.community
		profile_ban.trade
		users.site_admin
			-> badge (class: .label.label-warning)
			-> color name (class: .admin-name)
		users.donation
			-> badge (class: .label.label-success)
			-> color name (class: .donator-name)
		users.beta
			-> badge (class: .label.label-primary)
			-> color name (class: .beta-name)
		profile_old_alias = [
			profile_old_alias.seen
				-> this is a UNIX timestamp
				-> convert UNIX timestamp to readable DATE ("M j Y, g:i a")
					ex. Mar 0 2015, 10:57 am
			profile_old_alias.seen_alias
		]
		TIMES_CHECKED = [ (FROM CACHE)
			NUMBER OF TIMES CHECKED
			TIMESTAMP - UNIX
		]
		TIMES_ADDED = [ (FROM CACHE)
			NUMBER OF TIMES ADDED
			TIMESTAMP - UNIX
		]

	]

 **/

class SingleProfile extends BaseUpdate
{
	protected $smallId;

	function __construct($smallId)
	{
		$this->smallId = (int) $smallId;
		$this->cacheName = "profile_$smallId";
	}

	public function getProfile()
	{
		if($this->canUpdate()) return $this->updateUsingAPI();

		$return = $this->grabCache();
		if($return !== false) return $return;
		return $this->grabFromDB();
	}

	protected function updateCache($data)
	{
		unset($data['login_check']);

		parent::updateCache($data);
	}

	protected function grabCache()
	{
		if(!Cache::has($this->cacheName)) return false;

		$return = Cache::get($this->cacheName);

		/* getting the number of times checked and added */
		$gettingCount = UserListProfile::whereProfileId($return['id'])
			->orderBy('id','desc')
			->get();

		$profileTimesAdded = [
			'number' => $gettingCount->count(),
			'time' => isset($gettingCount[0]) ? (new DateTime($gettingCount[0]->created_at))->format("M j Y") : null
		];

		$profileCheckCache = "profile_checked_";

		$currentProfileCheck = [
			'number' => 0,
			'time' => date("M j Y", time())
		];

		if(Cache::has($profileCheckCache.$this->smallId)) $currentProfileCheck = Cache::get($profileCheckCache.$this->smallId);

		$newProfileCheck = [
			'number' => $currentProfileCheck['number'] + 1,
			'time' => date("M j Y", time())
		];

		Cache::forever($profileCheckCache.$this->smallId, $newProfileCheck);

		$return['times_checked'] = $currentProfileCheck;
		$return['times_added'] = $profileTimesAdded;

		return $return;
	}

	private function updateUsingAPI()
	{
		/* Time to follow that great guide to updating via API above */

		/* grab 'info' from web api and handle errors */
		$steamAPI = new SteamAPI('info');
		$steamAPI->setSmallId($this->smallId);
		$steamInfo = $steamAPI->run();

		if($steamAPI->error()) return ['error' => $steamAPI->errorMessage()];
		if(!isset($steamInfo->response->players[0])) return ['error' => 'profile_null'];

		$steamInfo = $steamInfo->response->players[0];

		/* grab 'ban' from web api and handle errors */
		$steamAPI = new SteamAPI('ban');
		$steamAPI->setSmallId($this->smallId);
		$steamBan = $steamAPI->run();

		if($steamAPI->error()) return ['error' => $steamAPI->errorMessage()];
		if(!isset($steamBan->players[0])) return ['error' => 'profile_null'];

		$steamBan = $steamBan->players[0];

		/* grab 'alias' from old web api but do not break on errors */
		$steamAPI = new SteamAPI('alias');
		$steamAPI->setSmallId($this->smallId);
		$steamAlias = $steamAPI->run();

		if($steamAPI->error()) { $steamAlias = []; }
		else { usort($steamAlias, array('VacStatus\Steam\Steam', 'aliasSort')); }

		/* Successfully passed steam's not very reliable api servers */
		/* Lets hope we got the alias as well :))) */

		/* Lets start up with profile table */
		$profile = Profile::whereSmallId($this->smallId)->first();

		if(!isset($profile->id))
		{
			$profile = new Profile;
			$profile->small_id = $this->smallId;

			if(isset($steamInfo->timecreated)) // people like to hide their info because smurf or hack
			{
				$profile->profile_created = $steamInfo->timecreated;
			}
		} else {
			// Make sure to update if this was private and now suddenly public
			if(empty($profile->profile_created) && isset($steamInfo->timecreated)) {
				$profile->profile_created = $steamInfo->timecreated;
			}
		}

		$profile->display_name = $steamInfo->personaname;
		$profile->avatar = Steam::imgToHTTPS($steamInfo->avatarfull);
		$profile->avatar_thumb = Steam::imgToHTTPS($steamInfo->avatar);
		$profile->privacy = $steamInfo->communityvisibilitystate;
		$profile->alias = json_encode($steamAlias);

		if(!$profile->save()) return ['error' => 'profile_save_error'];

		/* Now to do profile_ban table */
		$profileBan = $profile->ProfileBan;

		// Dont update the profile_ban if there is nothing to update
		// This has to do with in the future when I check for new bans to notify/email
		$skipProfileBan = false;

		$newVacBanDate = new DateTime();
		$newVacBanDate->sub(new DateInterval("P{$steamBan->DaysSinceLastBan}D"));

		if(!isset($profileBan->id))
		{
			$profileBan = new ProfileBan;
			$profileBan->profile_id = $profile->id;
			$profileBan->unban = false;
		} else {
			$skipProfileBan = $profileBan->skipProfileBanUpdate($steamBan);

			if($profileBan->vac > $steamBan->NumberOfVACBans)
			{
			  $profileBan->unban = true;
			}
		}

		$profileBan->vac = $steamBan->NumberOfVACBans;
		$profileBan->community = $steamBan->CommunityBanned;
		$profileBan->trade = $steamBan->EconomyBan != 'none';
		$profileBan->vac_banned_on = $newVacBanDate->format('Y-m-d');

		if(!$skipProfileBan) if(!$profile->ProfileBan()->save($profileBan)) return ['error' => 'profile_ban_save_error'];


		/* Time to do profile_old_alias */
		/* Checks to make sure if there is already a same name before inserting new name */
		$profileOldAlias = $profile->ProfileOldAlias()->whereProfileId($profile->id)->orderBy('id','desc')->get();
		$currentTime = new DateTime();

		if($profileOldAlias->count() == 0)
		{
			$profileOldAlias = new ProfileOldAlias;
			$profileOldAlias->profile_id = $profile->id;
			$profileOldAlias->seen = $currentTime->format('Y-m-d');
			$profileOldAlias->seen_alias = $profile->display_name;
			$profileOldAlias->save();
		} else {
			$match = false;
			$recent = 0;
			foreach($profileOldAlias as $oldAlias)
			{
				if(is_object($oldAlias))
				{
					if($oldAlias->seen_alias == $profile->display_name)
					{
						$match = true;
						break;
					}

					$recent = $oldAlias->compareTime($recent);
				}
			}

			if(!$match && $recent + Steam::$UPDATE_TIME < time())
			{
				$currentTime = new DateTime();

				$newAlias = new ProfileOldAlias;
				$newAlias->profile_id = $profile->id;
				$newAlias->seen = $currentTime->format('Y-m-d');
				$newAlias->seen_alias = $profile->display_name;
				$profile->ProfileOldAlias()->save($newAlias);
			}
		}

		$profileOldAlias = $profile->ProfileOldAlias()->whereProfileId($profile->id)->orderBy('id','desc')->get();

		/* Finished inserting / updating into the DB! */

		/* Check to see if this user has an account in vacstatus */
		$user = User::where('small_id', $this->smallId)->first();

		/* getting the number of times checked and added */

		$gettingCount = UserListProfile::whereProfileId($profile->id)
			->orderBy('id','desc')
			->get();

		$profileTimesAdded = [
			'number' => $gettingCount->count(),
			'time' => isset($gettingCount[0]) ? (new DateTime($gettingCount[0]->created_at))->format("M j Y") : null
		];

		$profileCheckCache = "profile_checked_";

		$currentProfileCheck = [
			'number' => 0,
			'time' => date("M j Y", time())
		];

		if(Cache::has($profileCheckCache.$this->smallId)) $currentProfileCheck = Cache::get($profileCheckCache.$this->smallId);

		$newProfileCheck = [
			'number' => $currentProfileCheck['number'] + 1,
			'time' => date("M j Y", time())
		];

		Cache::forever($profileCheckCache.$this->smallId, $newProfileCheck);

		/* Writing the return array for the single profile */

		$steam64BitId = Steam::to64Bit($profile->small_id);

		$oldAliasArray = [];

		foreach($profileOldAlias as $k => $oldAlias)
		{
			if($oldAlias === true) 
			{
				$oldAliasArray[] = [
					"newname" => $profileOldAlias->seen_alias,
					"timechanged" => $profileOldAlias->seen->format("M j Y")
				];
				break;
			}
			$oldAliasArray[] = [
				"newname" => $oldAlias->seen_alias,
				"timechanged" => $oldAlias->seen->format("M j Y")
			];
		}

		$return = [
			'id'				=> $profile->id,
			'display_name'		=> $steamInfo->personaname,
			'avatar'			=> Steam::imgToHTTPS($steamInfo->avatarfull),
			'avatar_thumb'		=> Steam::imgToHTTPS($steamInfo->avatar),
			'small_id'			=> $this->smallId,
			'steam_64_bit'		=> $steam64BitId,
			'steam_32_bit'		=> Steam::to32Bit($steam64BitId),
			'profile_created'	=> isset($profile->profile_created) ? date("M j Y", $profile->profile_created) : "Unknown",
			'privacy'			=> $steamInfo->communityvisibilitystate,
			'alias'				=> Steam::friendlyAlias($steamAlias),
			'created_at'		=> $profile->created_at->format("M j Y"),
			'vac'				=> $steamBan->NumberOfVACBans,
			'vac_banned_on'		=> $newVacBanDate->format("M j Y"),
			'community'			=> $steamBan->CommunityBanned,
			'trade'				=> $steamBan->EconomyBan != 'none',
			'site_admin'		=> isset($user->id) ? $user->site_admin : 0,
			'donation'			=> isset($user->id) ? $user->donation : 0,
			'beta'				=> isset($user->id) ? $user->beta : 0,
			'profile_old_alias'	=> $oldAliasArray,
			'times_checked'		=> $currentProfileCheck,
			'times_added'		=> $profileTimesAdded,
		];

		/* YAY nothing broke :D time to return the data (and update cache) */
		$this->updateCache($return);
		return $return;
	}

	private function grabFromDB()
	{
		$profile = Profile::where('profile.small_id', $this->smallId)
			->leftjoin('profile_ban', 'profile.id', '=', 'profile_ban.profile_id')
			->leftjoin('users', 'profile.small_id', '=', 'users.small_id')
			->first([
				'profile.id',
				'profile.display_name',
				'profile.avatar',
				'profile.avatar_thumb',
				'profile.small_id',
				'profile.profile_created',
				'profile.privacy',
				'profile.alias',
				'profile.created_at',

				'profile_ban.vac',
				'profile_ban.vac_banned_on',
				'profile_ban.community',
				'profile_ban.trade',

				'users.site_admin',
				'users.donation',
				'users.beta',
			]);

		/* Copied and pasted from function above */
		$profileOldAlias = $profile->ProfileOldAlias()->whereProfileId($profile->id)->orderBy('id','desc')->get();

		$gettingCount = UserListProfile::whereProfileId($profile->id)
			->orderBy('id','desc')
			->whereNull('deleted_at')
			->get();

		$profileTimesAdded = [
			'number' => $gettingCount->count(),
			'time' => isset($gettingCount[0]) ? (new DateTime($gettingCount[0]->created_at))->format("M j Y") : null
		];

		$profileCheckCache = "profile_checked_";

		$currentProfileCheck = [
			'number' => 0,
			'time' => date("M j Y", time())
		];

		if(Cache::has($profileCheckCache.$this->smallId)) $currentProfileCheck = Cache::get($profileCheckCache.$this->smallId);

		$newProfileCheck = [
			'number' => $currentProfileCheck['number'] + 1,
			'time' => date("M j Y", time())
		];

		Cache::forever($profileCheckCache.$this->smallId, $newProfileCheck);

		/* WOW THAT WAS SHORT!!!!! */

		$steam64BitId = Steam::to64Bit($profile->small_id);

		$oldAliasArray = [];

		foreach($profileOldAlias as $k => $oldAlias)
		{
			$oldAliasArray[] = [
				"newname" => $oldAlias->seen_alias,
				"timechanged" => $oldAlias->seen->format("M j Y")
			];
		}

		$return = [
			'id'				=> $profile->id,
			'display_name'		=> $profile->display_name,
			'avatar'			=> $profile->avatar,
			'small_id'			=> $profile->small_id,
			'steam_64_bit'		=> $steam64BitId,
			'steam_32_bit'		=> Steam::to32Bit($steam64BitId),
			'profile_created'	=> isset($profile->profile_created) ? date("M j Y", $profile->profile_created) : "Unknown",
			'privacy'			=> $profile->privacy,
			'alias'				=> Steam::friendlyAlias(json_decode($profile->alias)),
			'created_at'		=> $profile->created_at->format("M j Y"),
			'vac'				=> $profile->vac,
			'vac_banned_on'		=> $profile->vac_banned_on->format("M j Y"),
			'community'			=> $profile->community,
			'trade'				=> $profile->trade,
			
			'site_admin'		=> $profile->site_admin,
			'donation'			=> $profile->donation,
			'beta'				=> $profile->beta,

			'profile_old_alias'	=> $oldAliasArray,
			'times_checked'		=> $currentProfileCheck,
			'times_added'		=> $profileTimesAdded,
		];

		return $return;
	}
}