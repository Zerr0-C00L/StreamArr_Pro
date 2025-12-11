import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { streamarrApi } from '../services/api';
import ChannelCard from '../components/ChannelCard';
import { Search, Radio, Filter } from 'lucide-react';

export default function LiveTV() {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string>('all');

  const { data: channels, isLoading } = useQuery({
    queryKey: ['channels', selectedCategory],
    queryFn: () => {
      const category = selectedCategory === 'all' ? undefined : selectedCategory;
      return streamarrApi.getChannels({ category }).then(res => res.data);
    },
  });

  const filteredChannels = channels?.filter(ch =>
    ch.name.toLowerCase().includes(searchQuery.toLowerCase())
  ) || [];

  // Get unique categories
  const categories = ['all', ...new Set(channels?.map(ch => ch.category) || [])];

  const handleChannelClick = (id: number) => {
    // TODO: Open video player modal
    console.log('Channel clicked:', id);
  };

  return (
    <div className="p-8">
      {/* Header */}
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold text-white mb-2 flex items-center gap-3">
            <Radio className="w-8 h-8 text-red-500" />
            Live TV
          </h1>
          <p className="text-slate-400">
            {filteredChannels.length} channels available
          </p>
        </div>
        
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-2 text-sm">
            <span className="relative flex h-3 w-3">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
            </span>
            <span className="text-red-500 font-medium">
              {channels?.filter(ch => ch.active).length || 0} LIVE
            </span>
          </div>
        </div>
      </div>

      {/* Search and Category Filter */}
      <div className="flex gap-4 mb-6">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400 w-5 h-5" />
          <input
            type="text"
            placeholder="Search channels..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="input w-full pl-10"
          />
        </div>

        <div className="flex items-center gap-2">
          <Filter className="w-5 h-5 text-slate-400" />
          <select
            value={selectedCategory}
            onChange={(e) => setSelectedCategory(e.target.value)}
            className="input"
          >
            {categories.map(cat => (
              <option key={cat} value={cat}>
                {cat === 'all' ? 'All Categories' : cat}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Channels List */}
      {isLoading ? (
        <div className="text-center py-20">
          <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-primary-500"></div>
          <p className="text-slate-400 mt-4">Loading channels...</p>
        </div>
      ) : filteredChannels.length === 0 ? (
        <div className="text-center py-20">
          <Radio className="w-24 h-24 text-slate-600 mx-auto mb-4" />
          <h3 className="text-xl font-semibold text-white mb-2">
            {searchQuery ? 'No channels found' : 'No channels available'}
          </h3>
          <p className="text-slate-400 mb-6">
            {searchQuery
              ? 'Try adjusting your search or category filter'
              : 'Configure your IPTV sources to start watching live TV'}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {filteredChannels.map((channel) => (
            <ChannelCard
              key={channel.id}
              channel={channel}
              onClick={() => handleChannelClick(channel.id)}
            />
          ))}
        </div>
      )}

      {/* Stats Footer */}
      {filteredChannels.length > 0 && (
        <div className="mt-8 flex items-center justify-between text-sm text-slate-400">
          <div>
            Showing {filteredChannels.length} of {channels?.length || 0} channels
          </div>
          <div className="flex gap-4">
            <span>Categories: {categories.length - 1}</span>
            <span>Active: {channels?.filter(ch => ch.active).length || 0}</span>
          </div>
        </div>
      )}
    </div>
  );
}
