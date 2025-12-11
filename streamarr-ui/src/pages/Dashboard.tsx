import { useQuery } from '@tanstack/react-query';
import { streamarrApi } from '../services/api';
import { Film, Tv, Check, TrendingUp, Radio } from 'lucide-react';

export default function Dashboard() {
  const { data: movies } = useQuery({
    queryKey: ['movies'],
    queryFn: () => streamarrApi.getMovies({ limit: 1000 }).then(res => res.data),
  });

  const { data: series } = useQuery({
    queryKey: ['series'],
    queryFn: () => streamarrApi.getSeries({ limit: 1000 }).then(res => res.data),
  });

  const { data: channels } = useQuery({
    queryKey: ['channels'],
    queryFn: () => streamarrApi.getChannels().then(res => res.data),
  });

  const stats = {
    totalMovies: movies?.length || 0,
    monitoredMovies: movies?.filter(m => m.monitored).length || 0,
    availableMovies: movies?.filter(m => m.available).length || 0,
    totalSeries: series?.length || 0,
    monitoredSeries: series?.filter(s => s.monitored).length || 0,
    totalEpisodes: series?.reduce((sum, s) => sum + s.total_episodes, 0) || 0,
    totalChannels: channels?.length || 0,
    liveChannels: channels?.filter(ch => ch.active).length || 0,
    recentlyAdded: movies?.slice(0, 10) || [],
  };

  const statCards = [
    {
      label: 'Total Movies',
      value: stats.totalMovies,
      icon: Film,
      color: 'bg-blue-500',
      subtitle: `${stats.monitoredMovies} monitored`,
    },
    {
      label: 'TV Series',
      value: stats.totalSeries,
      icon: Tv,
      color: 'bg-purple-500',
      subtitle: `${stats.totalEpisodes} episodes`,
    },
    {
      label: 'Live Channels',
      value: stats.totalChannels,
      icon: Radio,
      color: 'bg-red-500',
      subtitle: `${stats.liveChannels} active`,
    },
    {
      label: 'Available',
      value: stats.availableMovies,
      icon: Check,
      color: 'bg-green-500',
      subtitle: 'Ready to watch',
    },
  ];

  return (
    <div className="p-8">
      <div className="mb-8">
        <h1 className="text-3xl font-bold text-white mb-2">Dashboard</h1>
        <p className="text-slate-400">Overview of your media library</p>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        {statCards.map((stat) => (
          <div key={stat.label} className="card p-6">
            <div className="flex items-center justify-between mb-4">
              <div className={`${stat.color} p-3 rounded-lg`}>
                <stat.icon className="w-6 h-6 text-white" />
              </div>
            </div>
            <div className="text-3xl font-bold text-white mb-1">
              {stat.value.toLocaleString()}
            </div>
            <div className="text-slate-400 text-sm">{stat.label}</div>
            {stat.subtitle && (
              <div className="text-slate-500 text-xs mt-1">{stat.subtitle}</div>
            )}
          </div>
        ))}
      </div>

      {/* Recent Activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="card p-6">
          <div className="flex items-center gap-2 mb-4">
            <TrendingUp className="w-5 h-5 text-primary-500" />
            <h2 className="text-xl font-semibold text-white">Recently Added</h2>
          </div>
          
          <div className="space-y-3">
            {stats.recentlyAdded.length === 0 ? (
              <div className="text-center py-8 text-slate-400">
                <Film className="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>No movies added yet</p>
                <p className="text-sm">Start building your library!</p>
              </div>
            ) : (
              stats.recentlyAdded.map((movie) => (
                <div key={movie.id} className="flex items-center gap-3 p-3 bg-slate-700/50 rounded-lg hover:bg-slate-700 transition-colors">
                  <div className="w-12 h-16 bg-slate-600 rounded overflow-hidden flex-shrink-0">
                    {movie.poster_path && (
                      <img
                        src={`https://image.tmdb.org/t/p/w200${movie.poster_path}`}
                        alt={movie.title}
                        className="w-full h-full object-cover"
                      />
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-white font-medium truncate">{movie.title}</div>
                    <div className="text-slate-400 text-sm">
                      {movie.release_date ? new Date(movie.release_date).getFullYear() : 'N/A'}
                    </div>
                  </div>
                  {movie.monitored && (
                    <Check className="w-5 h-5 text-green-400 flex-shrink-0" />
                  )}
                </div>
              ))
            )}
          </div>
        </div>

        <div className="card p-6">
          <h2 className="text-xl font-semibold text-white mb-4">Quick Stats</h2>
          
          <div className="space-y-4">
            <div>
              <div className="flex justify-between text-sm mb-2">
                <span className="text-slate-400">Monitoring Rate</span>
                <span className="text-white">
                  {stats.totalMovies > 0 
                    ? Math.round((stats.monitoredMovies / stats.totalMovies) * 100)
                    : 0}%
                </span>
              </div>
              <div className="h-2 bg-slate-700 rounded-full overflow-hidden">
                <div
                  className="h-full bg-green-500 transition-all"
                  style={{
                    width: `${stats.totalMovies > 0 
                      ? (stats.monitoredMovies / stats.totalMovies) * 100
                      : 0}%`
                  }}
                />
              </div>
            </div>

            <div>
              <div className="flex justify-between text-sm mb-2">
                <span className="text-slate-400">Availability Rate</span>
                <span className="text-white">
                  {stats.totalMovies > 0 
                    ? Math.round((stats.availableMovies / stats.totalMovies) * 100)
                    : 0}%
                </span>
              </div>
              <div className="h-2 bg-slate-700 rounded-full overflow-hidden">
                <div
                  className="h-full bg-purple-500 transition-all"
                  style={{
                    width: `${stats.totalMovies > 0 
                      ? (stats.availableMovies / stats.totalMovies) * 100
                      : 0}%`
                  }}
                />
              </div>
            </div>

            <div className="pt-4 border-t border-slate-700">
              <div className="grid grid-cols-2 gap-4 text-center">
                <div>
                  <div className="text-2xl font-bold text-white">{stats.totalMovies}</div>
                  <div className="text-sm text-slate-400">Total Items</div>
                </div>
                <div>
                  <div className="text-2xl font-bold text-green-400">{stats.availableMovies}</div>
                  <div className="text-sm text-slate-400">Ready to Watch</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
