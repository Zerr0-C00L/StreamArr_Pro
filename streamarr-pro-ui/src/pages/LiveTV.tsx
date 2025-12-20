import { useState, useMemo, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { streamarrApi } from '../services/api';
import { 
  Search, Radio, Filter, Play, ChevronLeft, ChevronRight, 
  Loader2, ExternalLink, Tv, X, Maximize, Volume2, VolumeX, Pause
} from 'lucide-react';
import type { Channel } from '../types';
import Hls from 'hls.js';

const CHANNELS_PER_PAGE = 50;

export default function LiveTV() {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedChannel, setSelectedChannel] = useState<Channel | null>(null);
  const [playingChannel, setPlayingChannel] = useState<Channel | null>(null);

  const { data: channels = [], isLoading } = useQuery({
    queryKey: ['channels', selectedCategory],
    queryFn: () => {
      const category = selectedCategory === 'all' ? undefined : selectedCategory;
      return streamarrApi.getChannels({ category }).then(res => res.data || []);
    },
  });

  const filteredChannels = channels.filter((ch: Channel) =>
    ch.name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const totalPages = Math.ceil(filteredChannels.length / CHANNELS_PER_PAGE);
  
  const paginatedChannels = useMemo(() => {
    const startIndex = (currentPage - 1) * CHANNELS_PER_PAGE;
    return filteredChannels.slice(startIndex, startIndex + CHANNELS_PER_PAGE);
  }, [filteredChannels, currentPage]);

  const handleSearchChange = (value: string) => {
    setSearchQuery(value);
    setCurrentPage(1);
  };

  const handleCategoryChange = (value: string) => {
    setSelectedCategory(value);
    setCurrentPage(1);
  };

  const categories = ['all', ...new Set(channels.map((ch: Channel) => ch.category).filter(Boolean))];

  // Group channels by category for display
  const channelsByCategory = useMemo(() => {
    if (searchQuery || selectedCategory !== 'all') {
      return { [selectedCategory === 'all' ? 'Search Results' : selectedCategory]: paginatedChannels };
    }
    
    const grouped: Record<string, Channel[]> = {};
    channels.forEach((ch: Channel) => {
      const cat = ch.category || 'Other';
      if (!grouped[cat]) grouped[cat] = [];
      if (grouped[cat].length < 20) grouped[cat].push(ch);
    });
    return grouped;
  }, [channels, paginatedChannels, searchQuery, selectedCategory]);

  return (
    <div className="min-h-screen bg-[#141414] -m-6">
      {/* Hero Section */}
      <div className="relative h-[35vh] min-h-[250px] flex items-end pb-8 px-8">
        <div className="absolute inset-0 bg-gradient-to-b from-red-900/30 via-[#141414]/50 to-[#141414]" />
        <div className="absolute inset-0 bg-gradient-to-r from-[#141414] via-transparent to-[#141414]" />
        
        <div className="relative z-10 w-full">
          <div className="flex items-center gap-3 mb-4">
            <div className="p-3 rounded-full bg-red-600">
              <Radio className="w-8 h-8 text-white" />
            </div>
            <div>
              <h1 className="text-4xl font-black text-white">Live TV</h1>
              <p className="text-slate-400 mt-1">
                {filteredChannels.length} channels available
                <span className="ml-3 inline-flex items-center gap-1">
                  <span className="relative flex h-2 w-2">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span className="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                  </span>
                  <span className="text-red-500">{channels.filter((ch: Channel) => ch.active).length} Live</span>
                </span>
              </p>
            </div>
          </div>

          {/* Search and Filter */}
          <div className="flex gap-4 max-w-3xl">
            <div className="flex-1 relative">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
              <input
                type="text"
                placeholder="Search channels..."
                value={searchQuery}
                onChange={(e) => handleSearchChange(e.target.value)}
                className="w-full pl-12 pr-4 py-3 bg-[#333] border-2 border-transparent rounded-lg text-white 
                           placeholder-slate-500 focus:outline-none focus:border-white/30 transition-all"
              />
            </div>

            <div className="relative">
              <select
                value={selectedCategory}
                onChange={(e) => handleCategoryChange(e.target.value)}
                className="appearance-none px-4 py-3 pr-10 bg-[#333] text-white rounded-lg border-2 border-transparent
                           focus:outline-none focus:border-white/30 cursor-pointer min-w-[150px]"
              >
                {categories.map(cat => (
                  <option key={cat} value={cat}>
                    {cat === 'all' ? 'All Categories' : cat}
                  </option>
                ))}
              </select>
              <Filter className="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
            </div>
          </div>
        </div>
      </div>

      {/* Channels Content */}
      <div className="px-8 pb-12">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="w-10 h-10 animate-spin text-red-600" />
          </div>
        ) : filteredChannels.length === 0 ? (
          <div className="text-center py-20">
            <Radio className="w-20 h-20 text-slate-600 mx-auto mb-4" />
            <h3 className="text-2xl font-bold text-white mb-2">
              {searchQuery ? 'No channels found' : 'No channels available'}
            </h3>
            <p className="text-slate-400">
              {searchQuery ? 'Try a different search term' : 'Configure your IPTV sources in Settings'}
            </p>
          </div>
        ) : (
          <>
            {Object.entries(channelsByCategory).map(([category, categoryChannels]) => (
              <ChannelRow
                key={category}
                title={category}
                channels={categoryChannels}
                onChannelClick={setSelectedChannel}
              />
            ))}

            {/* Pagination */}
            {(searchQuery || selectedCategory !== 'all') && totalPages > 1 && (
              <div className="flex items-center justify-center gap-4 mt-8">
                <button
                  onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                  className="p-2 rounded-lg bg-[#333] text-white disabled:opacity-50 hover:bg-[#444] transition-colors"
                >
                  <ChevronLeft className="w-5 h-5" />
                </button>
                <span className="text-white">
                  Page {currentPage} of {totalPages}
                </span>
                <button
                  onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                  disabled={currentPage === totalPages}
                  className="p-2 rounded-lg bg-[#333] text-white disabled:opacity-50 hover:bg-[#444] transition-colors"
                >
                  <ChevronRight className="w-5 h-5" />
                </button>
              </div>
            )}
          </>
        )}
      </div>

      {/* Channel Detail Modal */}
      {selectedChannel && (
        <ChannelModal 
          channel={selectedChannel} 
          onClose={() => setSelectedChannel(null)}
          onPlay={setPlayingChannel}
        />
      )}

      {/* Video Player Modal */}
      {playingChannel && (
        <VideoPlayer
          channel={playingChannel}
          onClose={() => setPlayingChannel(null)}
        />
      )}
    </div>
  );
}

// Channel Row Component
function ChannelRow({ 
  title, 
  channels, 
  onChannelClick 
}: { 
  title: string; 
  channels: Channel[]; 
  onChannelClick: (channel: Channel) => void;
}) {
  if (channels.length === 0) return null;

  return (
    <div className="mb-8">
      <h2 className="text-xl font-bold text-white mb-4">{title}</h2>
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-4">
        {channels.map((channel) => (
          <ChannelCard 
            key={channel.id} 
            channel={channel} 
            onClick={() => onChannelClick(channel)} 
          />
        ))}
      </div>
    </div>
  );
}

// Channel Card Component
function ChannelCard({ channel, onClick }: { channel: Channel; onClick: () => void }) {
  return (
    <div
      onClick={onClick}
      className="bg-[#1e1e1e] rounded-xl overflow-hidden cursor-pointer group hover:bg-[#282828] 
                 hover:scale-105 transition-all duration-200"
    >
      <div className="relative aspect-video bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center">
        {channel.logo ? (
          <img
            src={channel.logo}
            alt={channel.name}
            className="w-full h-full object-contain p-4"
            onError={(e) => {
              (e.target as HTMLImageElement).style.display = 'none';
            }}
          />
        ) : (
          <Tv className="w-12 h-12 text-slate-600" />
        )}
        
        {/* Play overlay */}
        <div className="absolute inset-0 bg-black/60 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
          <div className="p-3 rounded-full bg-red-600">
            <Play className="w-6 h-6 text-white fill-white" />
          </div>
        </div>

        {/* Live indicator */}
        {channel.active && (
          <div className="absolute top-2 right-2 flex items-center gap-1 px-2 py-0.5 bg-red-600 rounded text-xs font-bold text-white">
            <span className="w-1.5 h-1.5 rounded-full bg-white animate-pulse" />
            LIVE
          </div>
        )}
      </div>

      <div className="p-3">
        <h3 className="text-white font-medium text-sm truncate">{channel.name}</h3>
        <p className="text-slate-500 text-xs truncate">{channel.category || 'Uncategorized'}</p>
      </div>
    </div>
  );
}

// Channel Modal Component
function ChannelModal({ channel, onClose, onPlay }: { channel: Channel; onClose: () => void; onPlay: (channel: Channel) => void }) {
  const handlePlay = () => {
    onPlay(channel);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" onClick={onClose}>
      <div 
        className="bg-[#181818] rounded-xl w-full max-w-lg overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="relative aspect-video bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center">
          {channel.logo ? (
            <img
              src={channel.logo}
              alt={channel.name}
              className="w-full h-full object-contain p-8"
            />
          ) : (
            <Tv className="w-24 h-24 text-slate-600" />
          )}
          
          <button
            onClick={onClose}
            className="absolute top-4 right-4 p-2 rounded-full bg-black/50 hover:bg-black/70 transition-colors"
          >
            <X className="w-5 h-5 text-white" />
          </button>

          {channel.active && (
            <div className="absolute top-4 left-4 flex items-center gap-1 px-3 py-1 bg-red-600 rounded text-sm font-bold text-white">
              <span className="w-2 h-2 rounded-full bg-white animate-pulse" />
              LIVE
            </div>
          )}
        </div>

        {/* Content */}
        <div className="p-6">
          <h2 className="text-2xl font-bold text-white mb-2">{channel.name}</h2>
          <p className="text-slate-400 mb-6">{channel.category || 'Uncategorized'}</p>

          <div className="flex gap-3">
            <button
              onClick={handlePlay}
              className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 
                         text-white font-bold rounded-lg transition-colors"
            >
              <Play className="w-5 h-5 fill-white" />
              Watch Now
            </button>
            <button
              onClick={() => channel.stream_url && navigator.clipboard.writeText(channel.stream_url)}
              className="px-4 py-3 bg-[#333] hover:bg-[#444] text-white rounded-lg transition-colors"
              title="Copy stream URL"
            >
              <ExternalLink className="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// Video Player Component
function VideoPlayer({ channel, onClose }: { channel: Channel; onClose: () => void }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const hlsRef = useRef<Hls | null>(null);
  const [isPlaying, setIsPlaying] = useState(true);
  const [isMuted, setIsMuted] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const video = videoRef.current;
    if (!video || !channel.stream_url) return;

    const streamUrl = channel.stream_url;

    // Check if HLS is supported
    if (Hls.isSupported()) {
      const hls = new Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 90,
      });
      
      hlsRef.current = hls;
      hls.loadSource(streamUrl);
      hls.attachMedia(video);
      
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        setLoading(false);
        video.play().catch((err) => {
          console.error('Auto-play failed:', err);
          setIsPlaying(false);
        });
      });

      hls.on(Hls.Events.ERROR, (_event, data) => {
        console.error('HLS error:', data);
        if (data.fatal) {
          switch (data.type) {
            case Hls.ErrorTypes.NETWORK_ERROR:
              setError('Network error - trying to recover...');
              hls.startLoad();
              break;
            case Hls.ErrorTypes.MEDIA_ERROR:
              setError('Media error - trying to recover...');
              hls.recoverMediaError();
              break;
            default:
              setError('Fatal error occurred');
              hls.destroy();
              break;
          }
        }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      // Native HLS support (Safari)
      video.src = streamUrl;
      video.addEventListener('loadedmetadata', () => {
        setLoading(false);
        video.play().catch((err) => {
          console.error('Auto-play failed:', err);
          setIsPlaying(false);
        });
      });
      video.addEventListener('error', () => {
        setError('Failed to load stream');
      });
    } else {
      setError('HLS not supported in this browser');
      setLoading(false);
    }

    return () => {
      if (hlsRef.current) {
        hlsRef.current.destroy();
      }
    };
  }, [channel.stream_url]);

  const togglePlay = () => {
    const video = videoRef.current;
    if (!video) return;

    if (video.paused) {
      video.play();
      setIsPlaying(true);
    } else {
      video.pause();
      setIsPlaying(false);
    }
  };

  const toggleMute = () => {
    const video = videoRef.current;
    if (!video) return;
    video.muted = !video.muted;
    setIsMuted(!isMuted);
  };

  const toggleFullscreen = () => {
    const video = videoRef.current;
    if (!video) return;

    if (!document.fullscreenElement) {
      video.requestFullscreen();
    } else {
      document.exitFullscreen();
    }
  };

  const handleExternalPlay = () => {
    if (channel.stream_url) {
      window.open(channel.stream_url, '_blank');
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/95" onClick={onClose}>
      <div 
        className="relative w-full max-w-6xl aspect-video bg-black"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Close button */}
        <button
          onClick={onClose}
          className="absolute top-4 right-4 z-10 p-2 rounded-full bg-black/50 hover:bg-black/70 transition-colors"
        >
          <X className="w-6 h-6 text-white" />
        </button>

        {/* Channel info */}
        <div className="absolute top-4 left-4 z-10 flex items-center gap-3">
          {channel.logo && (
            <img src={channel.logo} alt={channel.name} className="h-8 object-contain" />
          )}
          <div>
            <h3 className="text-white font-bold text-lg drop-shadow-lg">{channel.name}</h3>
            {channel.active && (
              <div className="flex items-center gap-1 text-red-500 text-sm font-medium">
                <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse" />
                LIVE
              </div>
            )}
          </div>
        </div>

        {/* Video element */}
        <video
          ref={videoRef}
          className="w-full h-full bg-black"
          controls={false}
          autoPlay
          playsInline
        />

        {/* Loading overlay */}
        {loading && (
          <div className="absolute inset-0 flex items-center justify-center bg-black/50">
            <Loader2 className="w-12 h-12 animate-spin text-white" />
          </div>
        )}

        {/* Error overlay */}
        {error && (
          <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/80 text-white">
            <Tv className="w-16 h-16 mb-4 text-red-500" />
            <p className="text-xl mb-2">{error}</p>
            <button
              onClick={handleExternalPlay}
              className="mt-4 px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg flex items-center gap-2"
            >
              <ExternalLink className="w-4 h-4" />
              Open in External Player
            </button>
          </div>
        )}

        {/* Controls overlay */}
        {!loading && !error && (
          <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <button
                  onClick={togglePlay}
                  className="p-2 rounded-full hover:bg-white/10 transition-colors"
                >
                  {isPlaying ? (
                    <Pause className="w-6 h-6 text-white fill-white" />
                  ) : (
                    <Play className="w-6 h-6 text-white fill-white" />
                  )}
                </button>
                <button
                  onClick={toggleMute}
                  className="p-2 rounded-full hover:bg-white/10 transition-colors"
                >
                  {isMuted ? (
                    <VolumeX className="w-6 h-6 text-white" />
                  ) : (
                    <Volume2 className="w-6 h-6 text-white" />
                  )}
                </button>
              </div>
              <div className="flex items-center gap-2">
                <button
                  onClick={handleExternalPlay}
                  className="p-2 rounded-full hover:bg-white/10 transition-colors"
                  title="Open in external player"
                >
                  <ExternalLink className="w-5 h-5 text-white" />
                </button>
                <button
                  onClick={toggleFullscreen}
                  className="p-2 rounded-full hover:bg-white/10 transition-colors"
                >
                  <Maximize className="w-6 h-6 text-white" />
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
